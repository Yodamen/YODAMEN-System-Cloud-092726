import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './customer-settings.css'


function validTypeColor(value) {
  const color = String(value || '').toUpperCase()
  return /^#[0-9A-F]{6}$/.test(color) ? color : '#343434'
}

function typeColorText(value) {
  const color = validTypeColor(value).slice(1)
  const r = parseInt(color.slice(0, 2), 16)
  const g = parseInt(color.slice(2, 4), 16)
  const b = parseInt(color.slice(4, 6), 16)
  return ((0.299 * r + 0.587 * g + 0.114 * b) / 255) > 0.62 ? '#171717' : '#FFFFFF'
}

function emptyType() {
  return { id: null, name: '', color: '#343434', min_completed_visits: 0, active: true }
}

function emptyClass() {
  return { id: null, name: '', min_lifetime_value: 0, active: true }
}

function activeRange(rows, target, key, formatter) {
  if (!target.active) return 'Inactive'
  const active = [...rows]
    .filter((row) => row.active)
    .sort((a, b) => Number(a[key] || 0) - Number(b[key] || 0))
  const index = active.findIndex((row) => String(row.id) === String(target.id))
  const current = Number(target[key] || 0)
  const next = index >= 0 ? active[index + 1] : null
  if (!next) return formatter(current, null)
  return formatter(current, Number(next[key] || 0))
}

function typeRange(rows, row) {
  return activeRange(rows, row, 'min_completed_visits', (current, next) => (
    next === null ? `${current}+ completed visits` : `${current}–${Math.max(current, next - 1)} completed visits`
  ))
}

function classRange(rows, row) {
  return activeRange(rows, row, 'min_lifetime_value', (current, next) => (
    next === null ? `${formatPeso(current)} and above` : `${formatPeso(current)} – below ${formatPeso(next)}`
  ))
}

function typePayload(rows) {
  return rows.map((row) => ({
    id: row.id || null,
    name: String(row.name || '').trim(),
    color: String(row.color || '#343434').toUpperCase(),
    min_completed_visits: Number(row.min_completed_visits || 0),
    active: Boolean(row.active),
  }))
}

function classPayload(rows) {
  return rows.map((row) => ({
    id: row.id || null,
    name: String(row.name || '').trim(),
    min_lifetime_value: Number(row.min_lifetime_value || 0),
    active: Boolean(row.active),
  }))
}

export default function CustomerMixPage() {
  const { hasPermission } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedTab = String(searchParams.get('tab') || 'class').toUpperCase()
  const [tab, setTab] = useState(requestedTab === 'TYPE' ? 'TYPE' : 'CLASS')
  const [typeRows, setTypeRows] = useState([])
  const [classRows, setClassRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [messageTone, setMessageTone] = useState('error')
  const [editor, setEditor] = useState(null)
  const [removing, setRemoving] = useState(null)

  const canEditType = hasPermission('customer-type-criteria', 'EDIT')
  const canEditClass = hasPermission('customer-class-criteria', 'EDIT')
  const canEdit = tab === 'TYPE' ? canEditType : canEditClass

  const load = useCallback(async () => {
    setLoading(true)
    setMessage('')
    try {
      const [typeResponse, classResponse] = await Promise.all([
        api.get('/v1/customers/type-criteria'),
        api.get('/v1/customers/class-criteria'),
      ])
      setTypeRows(typeResponse.data.criteria || [])
      setClassRows(classResponse.data.criteria || [])
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to load Customer Mix criteria.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    const next = String(searchParams.get('tab') || 'class').toUpperCase() === 'TYPE' ? 'TYPE' : 'CLASS'
    setTab(next)
  }, [searchParams])

  const sortedTypes = useMemo(
    () => [...typeRows].sort((a, b) => Number(a.min_completed_visits || 0) - Number(b.min_completed_visits || 0)),
    [typeRows],
  )
  const sortedClasses = useMemo(
    () => [...classRows].sort((a, b) => Number(a.min_lifetime_value || 0) - Number(b.min_lifetime_value || 0)),
    [classRows],
  )

  function switchTab(next) {
    setTab(next)
    setSearchParams({ tab: next.toLowerCase() }, { replace: true })
    setMessage('')
    setEditor(null)
    setRemoving(null)
  }

  function openAdd() {
    setEditor({ kind: tab, originalId: null, row: tab === 'TYPE' ? emptyType() : emptyClass() })
  }

  function openEdit(kind, row) {
    setEditor({ kind, originalId: row.id, row: { ...row } })
  }

  async function persistTypes(nextRows, successMessage) {
    const response = await api.put('/v1/customers/type-criteria', { criteria: typePayload(nextRows) })
    setTypeRows(response.data.criteria || [])
    setMessageTone('success')
    setMessage(successMessage)
  }

  async function persistClasses(nextRows, successMessage) {
    const response = await api.put('/v1/customers/class-criteria', { criteria: classPayload(nextRows) })
    setClassRows(response.data.criteria || [])
    setMessageTone('success')
    setMessage(successMessage)
  }

  async function saveEditor(event) {
    event.preventDefault()
    if (!editor) return

    setSaving(true)
    setMessage('')
    try {
      if (editor.kind === 'TYPE') {
        const nextRows = editor.originalId
          ? typeRows.map((row) => String(row.id) === String(editor.originalId) ? { ...editor.row, id: row.id } : row)
          : [...typeRows, { ...editor.row, id: null }]
        await persistTypes(nextRows, editor.originalId ? 'Customer Type updated.' : 'Customer Type added.')
      } else {
        const nextRows = editor.originalId
          ? classRows.map((row) => String(row.id) === String(editor.originalId) ? { ...editor.row, id: row.id } : row)
          : [...classRows, { ...editor.row, id: null }]
        await persistClasses(nextRows, editor.originalId ? 'Customer Class updated.' : 'Customer Class added.')
      }
      setEditor(null)
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, `Unable to save Customer ${editor.kind === 'TYPE' ? 'Type' : 'Class'}.`))
    } finally {
      setSaving(false)
    }
  }

  async function toggleStatus(kind, row) {
    setSaving(true)
    setMessage('')
    try {
      if (kind === 'TYPE') {
        const nextRows = typeRows.map((candidate) => String(candidate.id) === String(row.id) ? { ...candidate, active: !candidate.active } : candidate)
        await persistTypes(nextRows, `Customer Type ${row.active ? 'deactivated' : 'activated'}.`)
      } else {
        const nextRows = classRows.map((candidate) => String(candidate.id) === String(row.id) ? { ...candidate, active: !candidate.active } : candidate)
        await persistClasses(nextRows, `Customer Class ${row.active ? 'deactivated' : 'activated'}.`)
      }
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to update criteria status.'))
    } finally {
      setSaving(false)
    }
  }

  async function confirmRemove() {
    if (!removing) return
    setSaving(true)
    setMessage('')
    try {
      if (removing.kind === 'TYPE') {
        await persistTypes(
          typeRows.filter((row) => String(row.id) !== String(removing.row.id)),
          'Customer Type removed.',
        )
      } else {
        await persistClasses(
          classRows.filter((row) => String(row.id) !== String(removing.row.id)),
          'Customer Class removed.',
        )
      }
      setRemoving(null)
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to remove criteria.'))
    } finally {
      setSaving(false)
    }
  }

  function renderTable(kind) {
    const isType = kind === 'TYPE'
    const rows = isType ? sortedTypes : sortedClasses
    const allowed = isType ? canEditType : canEditClass

    if (loading) return <div className="empty-state">Loading Customer {isType ? 'Types' : 'Classes'}...</div>

    return (
      <div className="table-wrap customer-mix-table-wrap">
        <table className={`data-table customer-mix-table ${isType ? 'customer-mix-type-table' : 'customer-mix-class-table'}`}>
          <thead>
            <tr>
              <th>{isType ? 'Type' : 'Class'}</th>
              {isType ? <th>Badge Color</th> : null}
              <th>{isType ? 'Starts At Visits' : 'Starts At Lifetime Value'}</th>
              <th>Effective Range</th>
              <th>Status</th>
              <th className="actions-column">Action</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={`${kind}-${row.id}`}>
                <td>
                  <strong className="customer-mix-name">{row.name}</strong>
                  <span className="table-subline">{isType ? 'Visit-based classification' : 'Lifetime Value classification'}</span>
                </td>
                {isType ? (
                  <td>
                    <span className="customer-type-color-display">
                      <span className="customer-type-color-swatch" style={{ backgroundColor: row.color || '#343434' }} aria-hidden="true" />
                      <strong>{String(row.color || '#343434').toUpperCase()}</strong>
                    </span>
                  </td>
                ) : null}
                <td>
                  <strong>{isType ? Number(row.min_completed_visits || 0).toLocaleString() : formatPeso(row.min_lifetime_value || 0)}</strong>
                  <span className="table-subline">{isType ? 'completed visits' : 'minimum lifetime value'}</span>
                </td>
                <td><span className="customer-mix-range">{isType ? typeRange(rows, row) : classRange(rows, row)}</span></td>
                <td><span className={`status-pill ${row.active ? 'success' : 'neutral'}`}>{row.active ? 'Active' : 'Inactive'}</span></td>
                <td className="action-cell">
                  {allowed ? (
                    <ActionMenu items={[
                      { label: 'Edit', onClick: () => openEdit(kind, row) },
                      { label: row.active ? 'Deactivate' : 'Activate', onClick: () => toggleStatus(kind, row), disabled: saving },
                      { label: 'Remove', onClick: () => setRemoving({ kind, row }) },
                    ]} />
                  ) : '—'}
                </td>
              </tr>
            ))}
            {!rows.length ? (
              <tr>
                <td colSpan={isType ? 6 : 5}><div className="empty-state compact-empty">No Customer {isType ? 'Types' : 'Classes'} configured.</div></td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    )
  }

  const editorIsType = editor?.kind === 'TYPE'
  const removalIsType = removing?.kind === 'TYPE'

  return (
    <div className="page-stack customer-settings-page customer-mix-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Customer Mix <PageRefreshButton label="Refresh Customer Mix" disabled={loading || saving} onRefresh={load} /></h1>
          <p className="page-subtitle">Manage your customer classifications in one place. Types are based on completed visits, while Classes are based on Lifetime Value.</p>
        </div>
      </section>

      {message ? <div className={`form-alert ${messageTone}`}>{message}</div> : null}

      <div className="booking-tabs customer-mix-tabs" role="tablist" aria-label="Customer Mix criteria">
        <button type="button" className={tab === 'CLASS' ? 'active' : ''} onClick={() => switchTab('CLASS')}>Class</button>
        <button type="button" className={tab === 'TYPE' ? 'active' : ''} onClick={() => switchTab('TYPE')}>Type</button>
      </div>

      <section className="content-card customer-mix-card">
        <div className="section-heading-row compact-section-heading customer-mix-section-heading">
          <div>
            <h2>{tab === 'TYPE' ? 'Customer Type' : 'Customer Class'}</h2>
            <p>{tab === 'TYPE'
              ? 'Set the visit thresholds YODAMEN uses to classify customers automatically.'
              : 'Set the Lifetime Value thresholds YODAMEN uses to classify customers automatically.'}</p>
          </div>
          {canEdit ? <button className="primary-button compact-button" type="button" onClick={openAdd}>+ Add {tab === 'TYPE' ? 'Type' : 'Class'}</button> : null}
        </div>
        {renderTable(tab)}
      </section>

      {editor ? (
        <Modal
          title={`${editor.originalId ? 'Edit' : 'Add'} Customer ${editorIsType ? 'Type' : 'Class'}`}
          subtitle={editorIsType ? 'Visit-based customer classification' : 'Lifetime Value-based customer classification'}
          onClose={() => !saving && setEditor(null)}
          className="customer-mix-editor-modal"
        >
          <form onSubmit={saveEditor}>
            <div className="customer-mix-editor-grid">
              <label className="form-field">
                <span>{editorIsType ? 'Type Name' : 'Class Name'}</span>
                <input
                  autoFocus
                  required
                  value={editor.row.name}
                  placeholder={editorIsType ? 'e.g. Frequent' : 'e.g. Prime'}
                  onChange={(event) => setEditor((current) => ({ ...current, row: { ...current.row, name: event.target.value } }))}
                />
              </label>
              <label className="form-field">
                <span>{editorIsType ? 'Starts At Completed Visits' : 'Starts At Lifetime Value'}</span>
                <input
                  required
                  type="number"
                  min="0"
                  step={editorIsType ? '1' : '0.01'}
                  value={editorIsType ? editor.row.min_completed_visits : editor.row.min_lifetime_value}
                  onChange={(event) => setEditor((current) => ({
                    ...current,
                    row: {
                      ...current.row,
                      [editorIsType ? 'min_completed_visits' : 'min_lifetime_value']: event.target.value,
                    },
                  }))}
                />
              </label>
            </div>

            {editorIsType ? (
              <label className="form-field customer-type-color-field">
                <span>Customer Type Color</span>
                <div className="customer-type-color-picker">
                  <input
                    className="customer-type-color-input"
                    type="color"
                    value={validTypeColor(editor.row.color)}
                    onChange={(event) => setEditor((current) => ({ ...current, row: { ...current.row, color: event.target.value.toUpperCase() } }))}
                    aria-label="Customer Type badge color"
                  />
                  <input
                    className="customer-type-color-code"
                    value={String(editor.row.color || '#343434').toUpperCase()}
                    pattern="#[0-9A-Fa-f]{6}"
                    maxLength="7"
                    onChange={(event) => {
                      const value = event.target.value.toUpperCase()
                      setEditor((current) => ({ ...current, row: { ...current.row, color: value } }))
                    }}
                    onBlur={(event) => {
                      if (!/^#[0-9A-F]{6}$/.test(event.target.value.toUpperCase())) {
                        setEditor((current) => ({ ...current, row: { ...current.row, color: '#343434' } }))
                      }
                    }}
                    aria-label="Customer Type color hex code"
                  />
                  <span
                    className="customer-type-color-preview"
                    style={{ backgroundColor: validTypeColor(editor.row.color), color: typeColorText(editor.row.color) }}
                  >Badge preview</span>
                </div>
                <small>This color identifies the Customer Type wherever the Customer Class badge appears.</small>
              </label>
            ) : null}

            <label className="customer-mix-status-control">
              <input
                type="checkbox"
                checked={Boolean(editor.row.active)}
                onChange={(event) => setEditor((current) => ({ ...current, row: { ...current.row, active: event.target.checked } }))}
              />
              <span>
                <strong>Active</strong>
                <small>Use this classification in automatic customer assignment.</small>
              </span>
            </label>

            <div className="modal-actions">
              <button className="secondary-button" type="button" disabled={saving} onClick={() => setEditor(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : editor.originalId ? 'Save Changes' : `Add ${editorIsType ? 'Type' : 'Class'}`}</button>
            </div>
          </form>
        </Modal>
      ) : null}

      {removing ? (
        <ActionConfirmationModal
          title={`Remove Customer ${removalIsType ? 'Type' : 'Class'}?`}
          subtitle="Customer Mix configuration"
          message="This classification will be removed from the current Customer Mix setup."
          details={[
            { label: removalIsType ? 'Type' : 'Class', value: removing.row.name },
            { label: 'Current Range', value: removalIsType ? typeRange(sortedTypes, removing.row) : classRange(sortedClasses, removing.row) },
          ]}
          confirmLabel="Remove"
          danger
          busy={saving}
          onCancel={() => !saving && setRemoving(null)}
          onConfirm={confirmRemove}
        />
      ) : null}
    </div>
  )
}
