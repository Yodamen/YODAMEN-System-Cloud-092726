import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import './customer-settings.css'

const ORDER = ['NEW', 'RETURNEE', 'REGULAR', 'LOYAL']

function effectiveRange(rows, index) {
  const current = Number(rows[index]?.min_completed_visits || 0)
  const next = rows[index + 1]
  if (!next) return `${current}+ completed visits`
  return `${current}–${Math.max(current, Number(next.min_completed_visits || 0) - 1)} completed visits`
}

export default function CustomerTypeCriteriaPage() {
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const canEdit = hasPermission('customer-type-criteria', 'EDIT')

  const load = useCallback(async () => {
    const response = await api.get('/v1/customers/type-criteria')
    const indexed = new Map((response.data.criteria || []).map((row) => [row.code, row]))
    setRows(ORDER.map((code) => indexed.get(code)).filter(Boolean))
  }, [])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Customer Type criteria.'))).finally(() => setLoading(false))
  }, [load])

  const ranges = useMemo(() => rows.map((_, index) => effectiveRange(rows, index)), [rows])

  function updateThreshold(index, value) {
    setRows((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, min_completed_visits: value } : row))
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const response = await api.put('/v1/customers/type-criteria', {
        criteria: rows.map((row) => ({ code: row.code, min_completed_visits: Number(row.min_completed_visits || 0) })),
      })
      setRows(response.data.criteria || [])
      setMessage('Customer Type criteria saved.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save Customer Type criteria.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-stack customer-settings-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Customer Type Criteria <PageRefreshButton label="Refresh customer type criteria" disabled={loading || saving} onRefresh={load} /></h1>
          <p className="page-subtitle">Customer Type is detected automatically from completed visits. Set where each type begins.</p>
        </div>
      </section>

      {message ? <div className={`form-alert ${message.endsWith('saved.') ? 'success' : 'error'}`}>{message}</div> : null}

      <section className="content-card">
        {loading ? <div className="empty-state">Loading criteria...</div> : (
          <form onSubmit={save}>
            <div className="table-wrap">
              <table className="data-table customer-criteria-table">
                <thead><tr><th>Customer Type</th><th>Starts At</th><th>Effective Range</th></tr></thead>
                <tbody>
                  {rows.map((row, index) => (
                    <tr key={row.code}>
                      <td><strong>{row.name}</strong><span className="table-subline">{row.code}</span></td>
                      <td>
                        <div className="criteria-number-field">
                          <input type="number" min={index === 0 ? 0 : 1} step="1" value={row.min_completed_visits} disabled={!canEdit || index === 0} onChange={(event) => updateThreshold(index, event.target.value)} />
                          <span>completed visits</span>
                        </div>
                      </td>
                      <td><strong>{ranges[index]}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {canEdit ? <div className="modal-actions customer-settings-actions"><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Criteria'}</button></div> : null}
          </form>
        )}
      </section>
    </div>
  )
}
