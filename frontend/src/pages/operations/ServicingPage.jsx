import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import ServiceList from '../../components/common/ServiceList'
import { useAuth } from '../../context/AuthContext'
import PageRefreshButton from '../../components/common/PageRefreshButton'

const emptyAddService = { service_id: '', quantity: 1, assigned_personnel_id: '', notes: '' }

export default function ServicingPage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ services: [], personnel: [], phase_notes: {} })
  const [rows, setRows] = useState([])
  const [manageVisitId, setManageVisitId] = useState(null)
  const [cancelService, setCancelService] = useState(null)
  const [addService, setAddService] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const canEdit = hasPermission('servicing', 'EDIT')
  const canComplete = hasPermission('servicing', 'COMPLETE')

  const managedVisit = useMemo(() => rows.find((visit) => visit.id === manageVisitId) || null, [rows, manageVisitId])

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/operations/servicing/bootstrap')
    setBootstrap(response.data)
  }, [])

  const loadVisits = useCallback(async () => {
    const response = await api.get('/v1/operations/servicing', { params: { status: 'IN_SERVICE' } })
    setRows(response.data.visits || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadBootstrap(), loadVisits()])
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Servicing.')))
        .finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [loadBootstrap, loadVisits])

  async function refreshPageData() {
    setMessage('')
    try {
      await Promise.all([loadBootstrap(), loadVisits()])
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh Servicing.'))
    }
  }

  async function refresh() {
    await loadVisits()
  }

  async function assignStaff(serviceLine, personnelId) {
    setSaving(true)
    setMessage('')
    try {
      await api.patch(`/v1/operations/visit-services/${serviceLine.id}/staff`, {
        assigned_personnel_id: personnelId ? Number(personnelId) : null,
      })
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update staff assignment.'))
    } finally {
      setSaving(false)
    }
  }

  async function completeLine(serviceLine) {
    if (!window.confirm(`Complete ${serviceLine.service_name}? This records operational completion only.`)) return
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/visit-services/${serviceLine.id}/complete`)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to complete service.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveCancel(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/visit-services/${cancelService.id}/cancel`, { reason: cancelService.reason })
      setCancelService(null)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to cancel service.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveAddService(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/servicing/${addService.visit_id}/services`, {
        service_id: Number(addService.service_id),
        quantity: Number(addService.quantity || 1),
        assigned_personnel_id: addService.assigned_personnel_id ? Number(addService.assigned_personnel_id) : null,
        notes: addService.notes,
      })
      setAddService(null)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to add service.'))
    } finally {
      setSaving(false)
    }
  }

  function statusClass(value) {
    if (value === 'COMPLETED') return 'success'
    if (value === 'IN_PROGRESS' || value === 'IN_SERVICE') return 'warning'
    return 'neutral'
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Servicing <PageRefreshButton label="Refresh servicing" disabled={loading || saving} onRefresh={refreshPageData} /></h1>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card live-operations-card">
        {loading ? <div className="empty-state live-empty-state">Loading Servicing...</div> : (
          <div className="table-wrap">
            <table className="data-table servicing-overview-table">
              <thead><tr><th>Visit</th><th>Booking</th><th>Check-In</th><th>Customer</th><th>Services</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {rows.map((visit) => (
                  <tr key={visit.id}>
                    <td><strong>{visit.code}</strong><span className="table-subline">{visit.queue_entry?.code || '—'}</span></td>
                    <td><strong>{visit.booking?.code || '—'}</strong></td>
                    <td>{visit.check_in_time || '—'}</td>
                    <td><strong>{visit.customer?.name || '—'}</strong></td>
                    <td><ServiceList services={visit.services} excludeCancelled /></td>
                    <td><span className={`status-pill ${statusClass(visit.status)}`}>{visit.status}</span></td>
                    <td className="action-cell"><ActionMenu items={[{ label: 'Manage', onClick: () => setManageVisitId(visit.id) }]} /></td>
                  </tr>
                ))}
                {!rows.length ? <tr><td colSpan="7"><div className="empty-state live-empty-state">No on-going services right now.</div></td></tr> : null}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {managedVisit && (
        <Modal title={`Servicing · ${managedVisit.code}`} onClose={() => setManageVisitId(null)} wide>
          <div className="servicing-manage-header">
            <div><strong>{managedVisit.customer?.name}</strong><span className="table-subline">{managedVisit.booking?.code} · Check-in {managedVisit.check_in_time || '—'}</span></div>
            {managedVisit.can_add_service && canEdit ? <button className="secondary-button mini" type="button" onClick={() => setAddService({ visit_id: managedVisit.id, visit_code: managedVisit.code, ...emptyAddService })}>+ Add Service</button> : null}
          </div>
          <div className="servicing-lines servicing-manage-lines">
            {managedVisit.services.map((line) => (
              <div className="servicing-line" key={line.id}>
                <div className="servicing-line-main">
                  <strong>{line.service_name}</strong>
                  <span className="table-subline">{line.code} · Qty {line.quantity} · {line.source_type}</span>
                  {line.cancellation_reason ? <span className="table-subline danger-text">Reason: {line.cancellation_reason}</span> : null}
                </div>
                <div className="servicing-staff-cell">
                  {line.can_edit && canEdit ? (
                    <select value={line.assigned_personnel_id || ''} disabled={saving} onChange={(e) => assignStaff(line, e.target.value)}>
                      <option value="">Unassigned</option>
                      {bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </select>
                  ) : <span>{line.assigned_personnel_name || 'Unassigned'}</span>}
                </div>
                <div className="servicing-time-cell">
                  <span className={`status-pill ${statusClass(line.status)}`}>{line.status}</span>
                  <span className="table-subline">Start {line.started_time || '—'}{line.completed_time ? ` · Done ${line.completed_time}` : ''}</span>
                </div>
                <div className="row-actions servicing-actions">
                  {line.can_complete && canComplete && line.status !== 'COMPLETED' ? <button className="link-button" type="button" disabled={saving} onClick={() => completeLine(line)}>Complete</button> : null}
                  {line.can_cancel && canEdit && line.status !== 'CANCELLED' ? <button className="link-button danger-link" type="button" disabled={saving} onClick={() => setCancelService({ id: line.id, name: line.service_name, reason: '' })}>Cancel</button> : null}
                  {line.completion_event ? <span className="table-subline">{line.completion_event.code}</span> : null}
                </div>
              </div>
            ))}
          </div>
          {managedVisit.retail_items.length ? <div className="servicing-retail-summary"><strong>Retail snapshot:</strong> {managedVisit.retail_items.map((item) => `${item.item_name} ×${item.quantity}`).join(', ')}</div> : null}
        </Modal>
      )}

      {cancelService && (
        <Modal title={`Cancel ${cancelService.name}`} subtitle="The service line remains in Visit history. Completed services cannot be cancelled." onClose={() => setCancelService(null)}>
          <form className="form-grid" onSubmit={saveCancel}>
            <label className="form-field form-span-2">Cancellation Reason<textarea required rows="4" value={cancelService.reason} onChange={(e) => setCancelService((current) => ({ ...current, reason: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancelService(null)}>Keep Service</button><button className="danger-button" type="submit" disabled={saving}>Cancel Service</button></div>
          </form>
        </Modal>
      )}

      {addService && (
        <Modal title={`Add Service · ${addService.visit_code}`} subtitle="Adds a NEW_SERVICE line directly to the active Visit." onClose={() => setAddService(null)}>
          <form className="form-grid" onSubmit={saveAddService}>
            <label className="form-field form-span-2">Service<select required value={addService.service_id} onChange={(e) => setAddService((current) => ({ ...current, service_id: e.target.value }))}><option value="">Select service</option>{bootstrap.services.map((service) => <option key={service.id} value={service.id}>{service.name}{service.current_price != null ? ` · ₱${service.current_price.toLocaleString()}` : ''}</option>)}</select></label>
            <label className="form-field">Quantity<input type="number" min="1" value={addService.quantity} onChange={(e) => setAddService((current) => ({ ...current, quantity: e.target.value }))} /></label>
            <label className="form-field">Assigned Staff<select value={addService.assigned_personnel_id} onChange={(e) => setAddService((current) => ({ ...current, assigned_personnel_id: e.target.value }))}><option value="">Unassigned</option>{bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>
            <label className="form-field form-span-2">Notes<textarea rows="3" value={addService.notes} onChange={(e) => setAddService((current) => ({ ...current, notes: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setAddService(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>Add Service</button></div>
          </form>
        </Modal>
      )}
    </div>
  )
}
