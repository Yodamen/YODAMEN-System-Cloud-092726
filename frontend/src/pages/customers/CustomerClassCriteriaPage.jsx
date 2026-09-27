import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './customer-settings.css'

function emptyRow() {
  return { id: null, name: '', min_lifetime_value: 0, active: true }
}

function effectiveRange(rows, index) {
  const current = Number(rows[index]?.min_lifetime_value || 0)
  const next = rows[index + 1]
  if (!next) return `${formatPeso(current)} and above`
  const nextValue = Number(next.min_lifetime_value || 0)
  return `${formatPeso(current)} – below ${formatPeso(nextValue)}`
}

export default function CustomerClassCriteriaPage() {
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const canEdit = hasPermission('customer-class-criteria', 'EDIT')

  const load = useCallback(async () => {
    const response = await api.get('/v1/customers/class-criteria')
    setRows(response.data.criteria || [])
  }, [])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Customer Class criteria.'))).finally(() => setLoading(false))
  }, [load])

  const sortedRows = useMemo(() => [...rows].sort((a, b) => Number(a.min_lifetime_value || 0) - Number(b.min_lifetime_value || 0)), [rows])

  function updateRow(target, key, value) {
    setRows((current) => current.map((row) => row === target ? { ...row, [key]: value } : row))
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const response = await api.put('/v1/customers/class-criteria', {
        criteria: rows.map((row) => ({
          id: row.id || null,
          name: row.name,
          min_lifetime_value: Number(row.min_lifetime_value || 0),
          active: Boolean(row.active),
        })),
      })
      setRows(response.data.criteria || [])
      setMessage('Customer Class criteria saved.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save Customer Class criteria.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-stack customer-settings-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Customer Class Criteria <PageRefreshButton label="Refresh customer class criteria" disabled={loading || saving} onRefresh={load} /></h1>
          <p className="page-subtitle">Customer Class is detected automatically from Lifetime Value. Create the class names and the value where each class begins.</p>
        </div>
        {canEdit ? <button className="primary-button" type="button" onClick={() => setRows((current) => [...current, emptyRow()])}>+ Add Class</button> : null}
      </section>

      {message ? <div className={`form-alert ${message.endsWith('saved.') ? 'success' : 'error'}`}>{message}</div> : null}

      <section className="content-card">
        {loading ? <div className="empty-state">Loading criteria...</div> : (
          <form onSubmit={save}>
            <div className="table-wrap">
              <table className="data-table customer-criteria-table customer-class-table">
                <thead><tr><th>Customer Class</th><th>Starts At Lifetime Value</th><th>Effective Range</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
                <tbody>
                  {sortedRows.map((row, index) => (
                    <tr key={row.id || `new-${index}`}>
                      <td><input className="criteria-inline-input" type="text" value={row.name} disabled={!canEdit} placeholder="e.g. Gold" onChange={(event) => updateRow(row, 'name', event.target.value)} required /></td>
                      <td><input className="criteria-inline-input money-input" type="number" min="0" step="0.01" value={row.min_lifetime_value} disabled={!canEdit} onChange={(event) => updateRow(row, 'min_lifetime_value', event.target.value)} required /></td>
                      <td><strong>{effectiveRange(sortedRows, index)}</strong></td>
                      <td><label className="criteria-checkbox"><input type="checkbox" checked={Boolean(row.active)} disabled={!canEdit} onChange={(event) => updateRow(row, 'active', event.target.checked)} /><span>{row.active ? 'Active' : 'Inactive'}</span></label></td>
                      <td className="action-cell">{canEdit ? <button className="text-button danger-text" type="button" onClick={() => setRows((current) => current.filter((candidate) => candidate !== row))}>Remove</button> : '—'}</td>
                    </tr>
                  ))}
                  {!sortedRows.length ? <tr><td colSpan="5"><div className="empty-state compact-empty">No Customer Classes configured yet. Customers remain Unclassified until you add criteria.</div></td></tr> : null}
                </tbody>
              </table>
            </div>
            {canEdit ? <div className="modal-actions customer-settings-actions"><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Classes'}</button></div> : null}
          </form>
        )}
      </section>
    </div>
  )
}
