import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import ConfirmationModal from '../../components/common/ConfirmationModal'
import ServiceList from '../../components/common/ServiceList'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate, formatDisplayTime } from '../../utils/formatters'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import PaymentIndicator from '../../components/operations/PaymentIndicator'
import QuickCustomerModal from '../../components/operations/QuickCustomerModal'

function toInputDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function activeDateRange() {
  const now = new Date()
  const end = new Date(now.getFullYear(), now.getMonth() + 2, 0)
  return { start: toInputDate(now), end: toInputDate(end) }
}

const today = toInputDate(new Date())
const emptyService = { service_id: '', quantity: 1, assigned_personnel_id: '', notes: '' }
const emptyRetail = { item_name: '', quantity: 1, unit_price: 0, notes: '' }

function emptyBooking() {
  return { id: null, customer_id: '', customer_type: 'NEW', booking_date: today, booking_time: '10:00', booking_source: 'RESERVATION', notes: '', services: [{ ...emptyService }], retail_items: [], advance_payment: { collected: false, amount: '', payment_method_reference_id: '', reference_no: '', notes: '' } }
}

export default function BookingsPage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ customers: [], services: [], personnel: [], payment_methods: [], can_receive_advance: false, phase_notes: {} })
  const [bookings, setBookings] = useState([])
  const [view, setView] = useState('ACTIVE')
  const [search, setSearch] = useState('')
  const initialRange = activeDateRange()
  const [startDate, setStartDate] = useState(initialRange.start)
  const [endDate, setEndDate] = useState(initialRange.end)
  const [form, setForm] = useState(null)
  const [rebook, setRebook] = useState(null)
  const [cancel, setCancel] = useState(null)
  const [quickCustomerOpen, setQuickCustomerOpen] = useState(false)
  const [checkInCandidate, setCheckInCandidate] = useState(null)
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/operations/bookings/bootstrap')
    setBootstrap(response.data)
    return response.data
  }, [])

  const loadBookings = useCallback(async () => {
    const response = await api.get('/v1/operations/bookings', { params: { view, start_date: startDate || undefined, end_date: endDate || undefined, search: search || undefined } })
    setBookings(response.data.bookings || [])
  }, [view, startDate, endDate, search])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadBootstrap(), loadBookings()]).catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load bookings.'))).finally(() => setLoading(false))
    }, 120)
    return () => window.clearTimeout(timer)
  }, [loadBootstrap, loadBookings])

  async function refreshPageData() {
    setMessage('')
    try {
      await Promise.all([loadBootstrap(), loadBookings()])
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh bookings.'))
    }
  }

  function changeView(nextView) {
    setView(nextView)
    if (nextView === 'ACTIVE') {
      const range = activeDateRange()
      setStartDate(range.start)
      setEndDate(range.end)
    } else {
      setStartDate('')
      setEndDate('')
    }
  }

  function openCreate() { setMessage(''); setForm(emptyBooking()) }
  function openEdit(booking) {
    setMessage('')
    setForm({
      id: booking.id,
      customer_id: String(booking.customer?.id || ''),
      customer_type: booking.customer_type,
      booking_date: booking.booking_date,
      booking_time: booking.booking_time,
      booking_source: booking.booking_source,
      notes: booking.notes || '',
      services: booking.services.map((line) => ({ service_id: String(line.service_id), quantity: line.quantity, assigned_personnel_id: line.assigned_personnel_id ? String(line.assigned_personnel_id) : '', notes: line.notes || '' })),
      retail_items: booking.retail_items.map((line) => ({ item_name: line.item_name, quantity: line.quantity, unit_price: line.unit_price, notes: line.notes || '' })),
      advance_payment: { collected: false, amount: '', payment_method_reference_id: '', reference_no: '', notes: '' },
    })
  }

  function updateService(index, key, value) { setForm((current) => ({ ...current, services: current.services.map((line, i) => i === index ? { ...line, [key]: value } : line) })) }
  function updateRetail(index, key, value) { setForm((current) => ({ ...current, retail_items: current.retail_items.map((line, i) => i === index ? { ...line, [key]: value } : line) })) }

  async function handleQuickCustomerCreated(customer) {
    const nextBootstrap = await loadBootstrap()
    if (!nextBootstrap.customers?.some((row) => row.id === customer.id)) {
      const option = { id: customer.id, code: customer.code, name: customer.full_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim(), mobile_number: customer.mobile_number }
      setBootstrap((current) => ({ ...current, customers: [...current.customers, option] }))
    }
    setForm((current) => current ? { ...current, customer_id: String(customer.id) } : current)
  }

  async function save(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const payload = {
        customer_id: Number(form.customer_id), customer_type: form.customer_type, booking_date: form.booking_date, booking_time: form.booking_time,
        booking_source: 'RESERVATION', notes: form.notes,
        services: form.services.filter((line) => line.service_id).map((line) => ({ ...line, service_id: Number(line.service_id), quantity: Number(line.quantity || 1), assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null })),
        retail_items: form.retail_items.filter((line) => line.item_name.trim()).map((line) => ({ ...line, quantity: Number(line.quantity || 1), unit_price: Number(line.unit_price || 0) })),
      }
      if (!form.id && form.advance_payment?.collected) {
        payload.advance_payment = {
          collected: true,
          amount: Number(form.advance_payment.amount || 0),
          payment_method_reference_id: form.advance_payment.payment_method_reference_id ? Number(form.advance_payment.payment_method_reference_id) : null,
          reference_no: form.advance_payment.reference_no || '',
          notes: form.advance_payment.notes || '',
        }
      }
      if (form.id) await api.put(`/v1/operations/bookings/${form.id}`, payload)
      else await api.post('/v1/operations/bookings', payload)
      setForm(null); await loadBookings()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to save booking.')) }
    finally { setSaving(false) }
  }

  async function saveRebook(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try { await api.post(`/v1/operations/bookings/${rebook.id}/rebook`, { booking_date: rebook.booking_date, booking_time: rebook.booking_time, reason: rebook.reason }); setRebook(null); await loadBookings() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to rebook appointment.')) }
    finally { setSaving(false) }
  }

  async function checkIn(booking) {
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/operations/bookings/${booking.id}/check-in`)
      setCheckInCandidate(null)
      await loadBookings()
    }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to check in booking.')) }
    finally { setSaving(false) }
  }

  async function saveCancel(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try { await api.post(`/v1/operations/bookings/${cancel.id}/cancel`, { reason: cancel.reason }); setCancel(null); await loadBookings() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to cancel booking.')) }
    finally { setSaving(false) }
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading"><h1 className="live-page-title">Bookings <PageRefreshButton label="Refresh bookings" disabled={loading || saving} onRefresh={refreshPageData} /></h1>{hasPermission('bookings', 'CREATE') && <button className="primary-button" type="button" onClick={openCreate}>New Booking</button>}</section>
      {message ? <div className="form-alert error">{message}</div> : null}
      <div className="booking-tabs"><button className={view === 'ACTIVE' ? 'active' : ''} type="button" onClick={() => changeView('ACTIVE')}>Active</button><button className={view === 'COMPLETED' ? 'active' : ''} type="button" onClick={() => changeView('COMPLETED')}>Completed</button><button className={view === 'CANCELLED' ? 'active' : ''} type="button" onClick={() => changeView('CANCELLED')}>Cancelled</button></div>
      <section className="content-card booking-list-card">
        <div className="booking-filter-panel">
          <label className="booking-filter-field">From<input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} /></label>
          <label className="booking-filter-field">To<input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} /></label>
          <label className="booking-filter-field booking-search-field">Search<input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Customer or booking ID" /></label>
        </div>
        {loading ? <div className="empty-state">Loading bookings...</div> : <div className="table-wrap"><table className="data-table"><thead><tr><th>Booking</th><th>Schedule</th><th>Customer</th><th>Services</th><th>Source</th><th>Payment</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{bookings.map((booking) => <tr key={booking.id}><td><strong>{booking.code}</strong></td><td><strong>{formatDisplayDate(booking.booking_date)}</strong><span className="table-subline">{formatDisplayTime(booking.booking_time)}</span></td><td><strong>{booking.customer?.name}</strong><span className="table-subline">{booking.customer?.mobile_number ? `${booking.customer.mobile_number} · ` : ''}{booking.customer_type}</span></td><td><ServiceList services={booking.services} />{booking.retail_items.length ? <span className="table-subline">+ {booking.retail_items.length} retail item{booking.retail_items.length === 1 ? '' : 's'}</span> : null}</td><td>{booking.booking_source}</td><td><PaymentIndicator advanceAmount={booking.advance_payment_total} /></td><td><span className={`status-pill ${booking.status === 'BOOKED' ? 'success' : 'neutral'}`}>{booking.status}</span></td><td className="action-cell"><ActionMenu items={[booking.can_check_in && hasPermission('bookings', 'EDIT') && hasPermission('queue', 'CREATE') ? { label: 'Check In', disabled: saving, onClick: () => setCheckInCandidate(booking) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(booking) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Rebook', onClick: () => setRebook({ id: booking.id, booking_date: booking.booking_date, booking_time: booking.booking_time, reason: '' }) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Cancel', danger: true, onClick: () => setCancel({ id: booking.id, code: booking.code, reason: '' }) } : null]} /></td></tr>)}{!bookings.length && <tr><td colSpan="8"><div className="empty-state">No bookings in this view.</div></td></tr>}</tbody></table></div>}
      </section>


      {checkInCandidate && (
        <ConfirmationModal
          customerName={checkInCandidate.customer?.name}
          bookingCode={checkInCandidate.code}
          services={checkInCandidate.services}
          cancelLabel="Cancel"
          confirmLabel="Check In"
          busy={saving}
          onCancel={() => setCheckInCandidate(null)}
          onConfirm={() => checkIn(checkInCandidate)}
        />
      )}

      {form && <Modal title={form.id ? 'Edit Booking' : 'Add Booking'} subtitle="Reservations remain editable until check-in." onClose={() => setForm(null)} wide><form className="form-grid" onSubmit={save}><div className="form-field form-span-2 customer-picker-field"><div className="field-label-row"><span>Customer</span>{hasPermission('customers', 'CREATE') ? <button type="button" className="secondary-button mini" onClick={() => setQuickCustomerOpen(true)}>+ New Customer</button> : null}</div><select value={form.customer_id} onChange={(e) => setForm((v) => ({ ...v, customer_id: e.target.value }))} required><option value="">Select customer</option>{bootstrap.customers.map((row) => <option key={row.id} value={row.id}>{row.name}{row.mobile_number ? ` · ${row.mobile_number}` : ''}</option>)}</select></div><label className="form-field">Customer Type<select value={form.customer_type} onChange={(e) => setForm((v) => ({ ...v, customer_type: e.target.value }))}>{bootstrap.customer_types?.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label><label className="form-field">Booking Source<select value="RESERVATION" disabled><option>Reservation</option></select></label><label className="form-field">Booking Date<input type="date" value={form.booking_date} onChange={(e) => setForm((v) => ({ ...v, booking_date: e.target.value }))} required /></label><label className="form-field">Booking Time<input type="time" value={form.booking_time} onChange={(e) => setForm((v) => ({ ...v, booking_time: e.target.value }))} required /></label><div className="section-box form-span-2"><div className="section-heading-row"><h3>Services</h3><button type="button" className="secondary-button mini" onClick={() => setForm((v) => ({ ...v, services: [...v.services, { ...emptyService }] }))}>+ Add Service</button></div>{form.services.map((line, index) => <div className="line-editor-grid" key={`service-${index}`}><label className="form-field">Service<select value={line.service_id} onChange={(e) => updateService(index, 'service_id', e.target.value)}><option value="">Select service</option>{bootstrap.services.map((row) => <option key={row.id} value={row.id}>{row.name}{row.current_price != null ? ` · ₱${row.current_price.toLocaleString()}` : ''}</option>)}</select></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateService(index, 'quantity', e.target.value)} /></label><label className="form-field">Assigned Staff<select value={line.assigned_personnel_id} onChange={(e) => updateService(index, 'assigned_personnel_id', e.target.value)}><option value="">Unassigned</option>{bootstrap.personnel.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><button type="button" className="line-remove-button" onClick={() => setForm((v) => ({ ...v, services: v.services.filter((_, i) => i !== index) }))}>Remove</button></div>)}</div><div className="section-box form-span-2"><div className="section-heading-row"><h3>Retail Purchases</h3><button type="button" className="secondary-button mini" onClick={() => setForm((v) => ({ ...v, retail_items: [...v.retail_items, { ...emptyRetail }] }))}>+ Add Retail</button></div>{form.retail_items.length ? form.retail_items.map((line, index) => <div className="line-editor-grid retail-line-grid" key={`retail-${index}`}><label className="form-field">Product<input value={line.item_name} onChange={(e) => updateRetail(index, 'item_name', e.target.value)} /></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateRetail(index, 'quantity', e.target.value)} /></label><label className="form-field">Unit Price<input type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => updateRetail(index, 'unit_price', e.target.value)} /></label><button type="button" className="line-remove-button" onClick={() => setForm((v) => ({ ...v, retail_items: v.retail_items.filter((_, i) => i !== index) }))}>Remove</button></div>) : <div className="inline-empty">No retail purchases added.</div>}</div>{!form.id && bootstrap.can_receive_advance ? <div className="section-box form-span-2"><div className="section-heading-row"><h3>Advance Payment</h3><label className="inline-check"><input type="checkbox" checked={form.advance_payment?.collected || false} onChange={(e) => setForm((v) => ({ ...v, advance_payment: { ...v.advance_payment, collected: e.target.checked } }))} /> Collected</label></div>{form.advance_payment?.collected ? <div className="advance-payment-grid"><label className="form-field advance-amount-field"><span>Amount</span><input type="number" min="0.01" step="0.01" required value={form.advance_payment.amount} onChange={(e) => setForm((v) => ({ ...v, advance_payment: { ...v.advance_payment, amount: e.target.value } }))} /></label><label className="form-field advance-method-field"><span>Payment Method</span><select required value={form.advance_payment.payment_method_reference_id} onChange={(e) => setForm((v) => ({ ...v, advance_payment: { ...v.advance_payment, payment_method_reference_id: e.target.value } }))}><option value="">Select method</option>{bootstrap.payment_methods.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select>{!bootstrap.payment_methods.length ? <small>Add an active Payment Method under Reference Lists first.</small> : null}</label><label className="form-field advance-reference-field"><span>Reference No.</span><input value={form.advance_payment.reference_no} onChange={(e) => setForm((v) => ({ ...v, advance_payment: { ...v.advance_payment, reference_no: e.target.value } }))} /></label></div> : <div className="inline-empty">No advance cash received for this booking.</div>}</div> : null}<label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => setForm((v) => ({ ...v, notes: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Booking'}</button></div></form></Modal>}

      {rebook && <Modal title="Rebook Appointment" subtitle="The same Booking ID is retained and the old schedule is recorded in append-only history." onClose={() => setRebook(null)}><form className="form-grid" onSubmit={saveRebook}><label className="form-field">New Date<input type="date" value={rebook.booking_date} onChange={(e) => setRebook((v) => ({ ...v, booking_date: e.target.value }))} required /></label><label className="form-field">New Time<input type="time" value={rebook.booking_time} onChange={(e) => setRebook((v) => ({ ...v, booking_time: e.target.value }))} required /></label><label className="form-field form-span-2">Reason / Notes<textarea rows="3" value={rebook.reason} onChange={(e) => setRebook((v) => ({ ...v, reason: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setRebook(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>Save Rebooking</button></div></form></Modal>}

      {cancel && <Modal title={`Cancel ${cancel.code}`} subtitle="This affects only a reservation that has not entered Queuing." onClose={() => setCancel(null)}><form className="form-grid" onSubmit={saveCancel}><label className="form-field form-span-2">Cancellation Reason<textarea rows="4" value={cancel.reason} onChange={(e) => setCancel((v) => ({ ...v, reason: e.target.value }))} required /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancel(null)}>Keep Booking</button><button className="danger-button" type="submit" disabled={saving}>Cancel Booking</button></div></form></Modal>}
      {quickCustomerOpen ? <QuickCustomerModal onClose={() => setQuickCustomerOpen(false)} onCreated={handleQuickCustomerCreated} /> : null}

    </div>
  )
}
