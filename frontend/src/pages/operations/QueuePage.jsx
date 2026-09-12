import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'

const emptyService = { service_id: '', quantity: 1, assigned_personnel_id: '', notes: '' }
const emptyRetail = { item_name: '', quantity: 1, unit_price: 0, notes: '' }
const emptyWalkIn = () => ({ customer_id: '', customer_type: 'NEW', notes: '', services: [{ ...emptyService }], retail_items: [] })

export default function QueuePage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ customers: [], services: [], personnel: [], customer_types: [], phase_notes: {} })
  const [rows, setRows] = useState([])
  const [date, setDate] = useState('')
  const [status, setStatus] = useState('WAITING')
  const [walkIn, setWalkIn] = useState(null)
  const [cancel, setCancel] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/operations/queue/bootstrap')
    setBootstrap(response.data)
    setDate((current) => current || response.data.business_date || '')
  }, [])

  const loadQueue = useCallback(async () => {
    const response = await api.get('/v1/operations/queue', { params: { date: date || undefined, status } })
    setRows(response.data.queue || [])
  }, [date, status])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadBootstrap(), loadQueue()]).catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load queue.'))).finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [loadBootstrap, loadQueue])

  const serviceCount = useMemo(() => rows.reduce((sum, row) => sum + row.services.length, 0), [rows])
  const canCreateWalkIn = hasPermission('queue', 'CREATE') && hasPermission('bookings', 'CREATE')

  function updateService(index, key, value) { setWalkIn((current) => ({ ...current, services: current.services.map((line, i) => i === index ? { ...line, [key]: value } : line) })) }
  function updateRetail(index, key, value) { setWalkIn((current) => ({ ...current, retail_items: current.retail_items.map((line, i) => i === index ? { ...line, [key]: value } : line) })) }

  async function saveWalkIn(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const payload = {
        customer_id: Number(walkIn.customer_id), customer_type: walkIn.customer_type, notes: walkIn.notes,
        services: walkIn.services.filter((line) => line.service_id).map((line) => ({ service_id: Number(line.service_id), quantity: Number(line.quantity || 1), assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null, notes: line.notes })),
        retail_items: walkIn.retail_items.filter((line) => line.item_name.trim()).map((line) => ({ item_name: line.item_name, quantity: Number(line.quantity || 1), unit_price: Number(line.unit_price || 0), notes: line.notes })),
      }
      await api.post('/v1/operations/queue/walk-ins', payload)
      setWalkIn(null); await loadQueue()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to check in Walk-In.')) }
    finally { setSaving(false) }
  }

  async function saveCancel(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try { await api.post(`/v1/operations/queue/${cancel.id}/cancel`, { reason: cancel.reason }); setCancel(null); await loadQueue() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to cancel queue entry.')) }
    finally { setSaving(false) }
  }

  return (
    <div className="page-stack">
      <section className="page-heading"><div><span className="eyebrow gold">Operations</span><h1>Queuing</h1><p>Today's physical waiting list for checked-in reservations and Walk-Ins. Priority is derived from reservation creation/schedule and actual Walk-In check-in timing.</p></div>{canCreateWalkIn && <button className="primary-button" type="button" onClick={() => { setMessage(''); setWalkIn(emptyWalkIn()) }}>+ New Walk-In</button>}</section>
      {message ? <div className="form-alert error">{message}</div> : null}
      <section className="phase-note"><strong>Phase boundary:</strong> {bootstrap.phase_notes?.advance_payment} {bootstrap.phase_notes?.servicing}</section>
      <section className="summary-grid compact-summary"><div className="summary-card"><span>Rows in View</span><strong>{rows.length}</strong></div><div className="summary-card"><span>Waiting Services</span><strong>{serviceCount}</strong></div><div className="summary-card"><span>Queue Date</span><strong className="summary-date">{date || 'Today'}</strong></div></section>
      <section className="content-card">
        <div className="toolbar-row"><div className="segmented-control"><button className={status === 'WAITING' ? 'active' : ''} onClick={() => setStatus('WAITING')}>Waiting</button><button className={status === 'CANCELLED' ? 'active' : ''} onClick={() => setStatus('CANCELLED')}>Cancelled</button></div><label className="form-field compact-field">Queue Date<input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></label></div>
        {loading ? <div className="empty-state">Loading queue...</div> : <div className="table-wrap"><table className="data-table"><thead><tr><th className="queue-priority-column">#</th><th>Customer</th><th>Source / Schedule</th><th>Check-In</th><th>Services</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td className="queue-priority-cell">{row.priority_no || '—'}</td><td><strong>{row.customer?.name}</strong><span className="table-subline">{row.code} · {row.customer?.mobile_number}</span></td><td><strong>{row.source === 'WALK_IN' ? 'Walk-In' : 'Reservation'}</strong><span className="table-subline">{row.source === 'RESERVATION' ? `${row.scheduled_date || ''} ${row.scheduled_time || ''}` : row.booking?.code}</span></td><td>{row.check_in_time}</td><td>{row.services.length ? row.services.map((service) => service.service_name).join(', ') : `${row.retail_items.length} retail item(s)`}<span className="table-subline">{row.services.map((service) => service.assigned_personnel_name).filter(Boolean).join(', ') || 'Staff unassigned'}</span></td><td><span className={`status-pill ${row.status === 'WAITING' ? 'success' : 'neutral'}`}>{row.status}</span></td><td className="row-actions">{row.can_cancel && hasPermission('queue', 'EDIT') ? <button className="link-button danger-link" onClick={() => setCancel({ id: row.id, code: row.code, reason: '' })}>Cancel</button> : <span className="table-subline">View only</span>}</td></tr>)}{!rows.length && <tr><td colSpan="7"><div className="empty-state">No queue entries for this date/status.</div></td></tr>}</tbody></table></div>}
      </section>

      {walkIn && <Modal title="New Walk-In" subtitle="Creates one Booking and one Queue entry in the same database transaction." onClose={() => setWalkIn(null)} wide><form className="form-grid" onSubmit={saveWalkIn}><label className="form-field form-span-2">Customer<select required value={walkIn.customer_id} onChange={(e) => setWalkIn((v) => ({ ...v, customer_id: e.target.value }))}><option value="">Select customer</option>{bootstrap.customers.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.mobile_number}</option>)}</select><small>Create a new customer first from Operations → Customers when needed.</small></label><label className="form-field">Customer Type<select value={walkIn.customer_type} onChange={(e) => setWalkIn((v) => ({ ...v, customer_type: e.target.value }))}>{bootstrap.customer_types.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label><label className="form-field">Source<input value="Walk-In" disabled /></label><div className="section-box form-span-2"><div className="section-heading-row"><h3>Services</h3><button type="button" className="secondary-button mini" onClick={() => setWalkIn((v) => ({ ...v, services: [...v.services, { ...emptyService }] }))}>+ Add Service</button></div>{walkIn.services.map((line, index) => <div className="line-editor-grid" key={`walk-service-${index}`}><label className="form-field">Service<select value={line.service_id} onChange={(e) => updateService(index, 'service_id', e.target.value)}><option value="">Select service</option>{bootstrap.services.map((row) => <option key={row.id} value={row.id}>{row.name}{row.current_price != null ? ` · ₱${row.current_price.toLocaleString()}` : ''}</option>)}</select></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateService(index, 'quantity', e.target.value)} /></label><label className="form-field">Assigned Staff<select value={line.assigned_personnel_id} onChange={(e) => updateService(index, 'assigned_personnel_id', e.target.value)}><option value="">Unassigned</option>{bootstrap.personnel.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><button type="button" className="line-remove-button" onClick={() => setWalkIn((v) => ({ ...v, services: v.services.filter((_, i) => i !== index) }))}>Remove</button></div>)}</div><div className="section-box form-span-2"><div className="section-heading-row"><h3>Retail Purchases</h3><button type="button" className="secondary-button mini" onClick={() => setWalkIn((v) => ({ ...v, retail_items: [...v.retail_items, { ...emptyRetail }] }))}>+ Add Retail</button></div>{walkIn.retail_items.length ? walkIn.retail_items.map((line, index) => <div className="line-editor-grid retail-line-grid" key={`walk-retail-${index}`}><label className="form-field">Product<input value={line.item_name} onChange={(e) => updateRetail(index, 'item_name', e.target.value)} /></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateRetail(index, 'quantity', e.target.value)} /></label><label className="form-field">Unit Price<input type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => updateRetail(index, 'unit_price', e.target.value)} /></label><button type="button" className="line-remove-button" onClick={() => setWalkIn((v) => ({ ...v, retail_items: v.retail_items.filter((_, i) => i !== index) }))}>Remove</button></div>) : <div className="inline-empty">No retail purchases added.</div>}</div><label className="form-field form-span-2">Notes<textarea rows="3" value={walkIn.notes} onChange={(e) => setWalkIn((v) => ({ ...v, notes: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setWalkIn(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Checking In...' : 'Check In Walk-In'}</button></div></form></Modal>}

      {cancel && <Modal title={`Cancel ${cancel.code}`} subtitle="The Queue record and linked Booking remain in history and are marked Cancelled." onClose={() => setCancel(null)}><form className="form-grid" onSubmit={saveCancel}><label className="form-field form-span-2">Cancellation Reason<textarea rows="4" required value={cancel.reason} onChange={(e) => setCancel((v) => ({ ...v, reason: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancel(null)}>Keep Waiting</button><button className="danger-button" type="submit" disabled={saving}>Cancel Queue Entry</button></div></form></Modal>}
    </div>
  )
}
