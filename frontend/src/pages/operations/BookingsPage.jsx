import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import ConfirmationModal from '../../components/common/ConfirmationModal'
import ServiceList from '../../components/common/ServiceList'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate, formatDisplayTime } from '../../utils/formatters'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import PaymentIndicator from '../../components/operations/PaymentIndicator'
import QuickCustomerModal from '../../components/operations/QuickCustomerModal'
import InitialPaymentCollection from '../../components/operations/InitialPaymentCollection'
import ServiceCreditClaimSection from '../../components/operations/ServiceCreditClaimSection'
import { paymentMethodRequiresProof, uploadSupportingDocuments } from '../../utils/supportingDocuments'

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
const emptyService = { service_id: '', service_credit_id: '', package_enrollment_id: '', quantity: 1, assigned_personnel_id: '', notes: '' }
const emptyRetail = { retail_product_id: '', item_name: '', sku: '', quantity: 1, unit_price: 0, assigned_personnel_id: '', notes: '' }
const emptyPaymentCollection = () => ({ collected: false, amount: '', advance_amount: '', payment_method_reference_id: '', reference_no: '', allocations: {}, supporting_files: [] })

function isPackageService(service) {
  return Number(service?.number_of_sessions || 1) > 1 || service?.service_count === 'MULTI_SESSION'
}

function serviceOptionLabel(service) {
  const sessions = Number(service?.number_of_sessions || 1)
  const sessionLabel = isPackageService(service) ? ` · ${sessions} Sessions` : ''
  const priceLabel = service.current_price != null ? ` · ₱${service.current_price.toLocaleString()}` : ''
  return `${service.name}${sessionLabel}${priceLabel}`
}

function retailStockError(lines, products) {
  const totals = new Map()
  for (const line of lines || []) {
    if (!line.retail_product_id) continue
    const id = String(line.retail_product_id)
    totals.set(id, (totals.get(id) || 0) + Math.max(1, Number(line.quantity || 1)))
  }

  for (const [id, requested] of totals.entries()) {
    const product = (products || []).find((row) => String(row.id) === id)
    if (!product) continue
    const available = Math.max(0, Number(product.available || 0))
    if (requested > available) {
      const uom = product.selling_uom || product.unit || 'unit'
      return `${product.name} has insufficient stock for this Booking. Available: ${available} ${uom}.`
    }
  }
  return ''
}


function adjustedServiceCredits(credits, lines) {
  return credits.map((credit) => {
    const selectedHere = lines.filter((line) => String(line.service_credit_id || '') === String(credit.id)).length
    return { ...credit, available_sessions: Math.max(0, Number(credit.available_sessions || 0) - selectedHere) }
  })
}

function emptyBooking() {
  return { id: null, customer_id: '', customer_type: '', booking_date: today, booking_time: '10:00', booking_source: 'RESERVATION', notes: '', services: [{ ...emptyService }], retail_items: [], payment_collection: emptyPaymentCollection() }
}

function hasCurrentAdvanceTarget(bookingForm) {
  if (!bookingForm) return false

  const hasNewService = (bookingForm.services || []).some(
    (line) => Boolean(line.service_id) && !line.service_credit_id,
  )
  const hasRetailPurchase = (bookingForm.retail_items || []).some(
    (line) => Boolean(line.retail_product_id) || String(line.item_name || '').trim() !== '',
  )

  return hasNewService || hasRetailPurchase
}

export default function BookingsPage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ customers: [], services: [], retail_products: [], personnel: [], retail_personnel: [], payment_methods: [], can_receive_advance: false, phase_notes: {} })
  const [bookings, setBookings] = useState([])
  const [view, setView] = useState('ACTIVE')
  const [search, setSearch] = useState('')
  const [customerName, setCustomerName] = useState('')
  const [serviceFilter, setServiceFilter] = useState('')
  const [sourceFilter, setSourceFilter] = useState('')
  const [paymentFilter, setPaymentFilter] = useState('')
  const initialRange = activeDateRange()
  const [startDate, setStartDate] = useState(initialRange.start)
  const [endDate, setEndDate] = useState(initialRange.end)
  const [form, setForm] = useState(null)
  const [rebook, setRebook] = useState(null)
  const [cancel, setCancel] = useState(null)
  const [quickCustomerOpen, setQuickCustomerOpen] = useState(false)
  const [onAccountData, setOnAccountData] = useState(null)
  const [serviceCredits, setServiceCredits] = useState([])
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
    const response = await api.get('/v1/operations/bookings', {
      params: {
        view,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
        search: search || undefined,
        customer_name: customerName || undefined,
        service_id: serviceFilter || undefined,
        source: sourceFilter || undefined,
        payment: paymentFilter || undefined,
      },
    })
    setBookings(response.data.bookings || [])
  }, [view, startDate, endDate, search, customerName, serviceFilter, sourceFilter, paymentFilter])

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

  function resetFilters() {
    const range = view === 'ACTIVE' ? activeDateRange() : { start: '', end: '' }
    setStartDate(range.start)
    setEndDate(range.end)
    setSearch('')
    setCustomerName('')
    setServiceFilter('')
    setSourceFilter('')
    setPaymentFilter('')
  }

  function openCreate() { setMessage(''); setOnAccountData(null); setServiceCredits([]); setForm(emptyBooking()) }
  function openEdit(booking) {
    setMessage('')
    const nextForm = {
      id: booking.id,
      customer_id: String(booking.customer?.id || ''),
      customer_type: booking.customer_type,
      booking_date: booking.booking_date,
      booking_time: booking.booking_time,
      booking_source: booking.booking_source,
      notes: booking.notes || '',
      services: booking.services.map((line) => ({
        service_id: String(line.service_id),
        service_credit_id: line.service_credit_id ? String(line.service_credit_id) : '',
        package_enrollment_id: line.package_enrollment_id ? String(line.package_enrollment_id) : '',
        quantity: line.quantity,
        assigned_personnel_id: line.assigned_personnel_id ? String(line.assigned_personnel_id) : '',
        notes: line.notes || '',
      })),
      retail_items: booking.retail_items.map((line) => ({ retail_product_id: line.retail_product_id ? String(line.retail_product_id) : '', item_name: line.item_name, sku: line.sku || '', quantity: line.quantity, unit_price: line.unit_price, assigned_personnel_id: line.assigned_personnel_id ? String(line.assigned_personnel_id) : '', notes: line.notes || '' })),
      payment_collection: emptyPaymentCollection(),
    }
    setForm(nextForm)
    loadCustomerContext(nextForm.customer_id, nextForm.id, nextForm.booking_date)
  }

  function updateService(index, key, value) {
    setForm((current) => ({
      ...current,
      services: current.services.map((line, i) => {
        if (i !== index) return line
        if (key !== 'selection') return { ...line, [key]: value }

        if (!value) return { ...emptyService, assigned_personnel_id: line.assigned_personnel_id, notes: line.notes }
        if (value.startsWith('credit:')) {
          const credit = serviceCredits.find((row) => String(row.id) === value.slice(7))
          if (!credit) return line
          return {
            ...line,
            service_id: String(credit.service_id),
            service_credit_id: String(credit.id),
            package_enrollment_id: String(credit.package_enrollment_id),
            quantity: 1,
          }
        }

        const serviceId = value.startsWith('service:') ? value.slice(8) : value
        const selected = bootstrap.services.find((service) => String(service.id) === String(serviceId))
        return {
          ...line,
          service_id: serviceId,
          service_credit_id: '',
          package_enrollment_id: '',
          quantity: isPackageService(selected) ? 1 : line.quantity,
        }
      }),
    }))
  }
  function updateRetail(index, key, value) {
    setForm((current) => ({
      ...current,
      retail_items: current.retail_items.map((line, i) => {
        if (i !== index) return line
        if (key !== 'product') return { ...line, [key]: value }
        if (!value) return { ...line, retail_product_id: '', item_name: '', sku: '', unit_price: 0 }
        const product = (bootstrap.retail_products || []).find((row) => String(row.id) === String(value))
        if (!product) return line
        return {
          ...line,
          retail_product_id: String(product.id),
          item_name: product.name,
          sku: product.sku || '',
          unit_price: Number(product.selling_price || 0),
        }
      }),
    }))
  }


  function addServiceCreditClaim({ credit, sessions, assignedPersonnelId }) {
    const quantity = Math.max(1, Number(sessions || 1))
    const claimedLines = Array.from({ length: quantity }, () => ({
      ...emptyService,
      service_id: String(credit.service_id),
      service_credit_id: String(credit.id),
      package_enrollment_id: String(credit.package_enrollment_id),
      quantity: 1,
      assigned_personnel_id: assignedPersonnelId || '',
    }))

    setForm((current) => current ? { ...current, services: [...current.services, ...claimedLines] } : current)
  }

  function removeServiceCreditClaim(serviceCreditId, assignedPersonnelId) {
    setForm((current) => current ? {
      ...current,
      services: current.services.filter((line) => !(
        String(line.service_credit_id || '') === String(serviceCreditId)
        && String(line.assigned_personnel_id || '') === String(assignedPersonnelId || '')
      )),
    } : current)
  }

  async function loadCustomerContext(customerId, bookingId = null, bookingDate = null) {
    setOnAccountData(null)
    setServiceCredits([])
    if (!customerId) return

    const params = {}
    if (bookingId) params.booking_id = bookingId
    if (bookingDate) params.booking_date = bookingDate

    const [onAccountResult, creditResult] = await Promise.allSettled([
      api.get(`/v1/sales/on-account/customers/${customerId}`),
      api.get(`/v1/operations/customers/${customerId}/service-credits`, { params }),
    ])

    setOnAccountData(onAccountResult.status === 'fulfilled' ? onAccountResult.value.data : null)
    setServiceCredits(creditResult.status === 'fulfilled' ? (creditResult.value.data.service_credits || []) : [])
  }

  async function selectCustomer(customerId) {
    const currentBookingId = form?.id || null
    const bookingDate = form?.booking_date || today
    const selectedCustomer = bootstrap.customers.find((row) => String(row.id) === String(customerId))
    setForm((current) => current ? {
      ...current,
      customer_id: customerId,
      customer_type: selectedCustomer?.customer_type || '',
      services: current.services.filter((line) => !line.service_credit_id),
      payment_collection: emptyPaymentCollection(),
    } : current)
    await loadCustomerContext(customerId, currentBookingId, bookingDate)
  }

  async function changeBookingDate(bookingDate) {
    const customerId = form?.customer_id
    const bookingId = form?.id || null
    setForm((current) => current ? { ...current, booking_date: bookingDate } : current)
    if (customerId) await loadCustomerContext(customerId, bookingId, bookingDate)
  }

  async function handleQuickCustomerCreated(customer) {
    const nextBootstrap = await loadBootstrap()
    if (!nextBootstrap.customers?.some((row) => row.id === customer.id)) {
      const option = { id: customer.id, code: customer.code, name: customer.full_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim(), mobile_number: customer.mobile_number }
      setBootstrap((current) => ({ ...current, customers: [...current.customers, option] }))
    }
    await selectCustomer(String(customer.id))
  }

  async function save(event) {
    event.preventDefault(); setMessage('')
    const stockError = retailStockError(form?.retail_items, bootstrap.retail_products)
    if (stockError) {
      setMessage(stockError)
      return
    }

    setSaving(true)
    try {
      const currentAdvanceEligible = hasCurrentAdvanceTarget(form)
      const payload = {
        customer_id: Number(form.customer_id), booking_date: form.booking_date, booking_time: form.booking_time,
        notes: form.notes,
        services: form.services.filter((line) => line.service_id).map((line) => ({ ...line, service_id: Number(line.service_id), service_credit_id: line.service_credit_id ? Number(line.service_credit_id) : null, quantity: Number(line.quantity || 1), assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null })),
        retail_items: form.retail_items.filter((line) => line.retail_product_id || line.item_name.trim()).map((line) => ({ retail_product_id: line.retail_product_id ? Number(line.retail_product_id) : null, item_name: line.item_name, quantity: Number(line.quantity || 1), unit_price: Number(line.unit_price || 0), assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null, notes: line.notes })),
      }
      if (!form.id && form.payment_collection?.collected) {
        const proofRequired = paymentMethodRequiresProof(form.payment_collection.payment_method_reference_id, bootstrap.payment_methods)
        if (proofRequired && !(form.payment_collection.supporting_files || []).length) {
          throw new Error('Payment proof is required for non-cash Booking payments such as GCash or bank transfer.')
        }
        const attachmentTokens = await uploadSupportingDocuments(
          form.payment_collection.supporting_files || [],
          'bookings',
          'BOOKING_PAYMENT_PROOF',
        )
        payload.payment_collection = {
          collected: true,
          amount: Number(form.payment_collection.amount || 0),
          advance_amount: currentAdvanceEligible
            ? (onAccountData?.outstanding_balance > 0 ? Number(form.payment_collection.advance_amount || 0) : Number(form.payment_collection.amount || 0))
            : 0,
          payment_method_reference_id: form.payment_collection.payment_method_reference_id ? Number(form.payment_collection.payment_method_reference_id) : null,
          reference_no: form.payment_collection.reference_no || '',
          attachment_tokens: attachmentTokens,
          allocations: Object.entries(form.payment_collection.allocations || {})
            .map(([allocationKey, value]) => {
              const amount = Number(value || 0)
              if (allocationKey.startsWith('opening:')) return { opening_receivable_id: Number(allocationKey.slice(8)), amount }
              const saleItemId = allocationKey.startsWith('sale:') ? allocationKey.slice(5) : allocationKey
              return { sale_item_id: Number(saleItemId), amount }
            })
            .filter((row) => row.amount > 0),
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

  const visibleServiceCredits = form ? adjustedServiceCredits(serviceCredits, form.services) : serviceCredits

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading"><h1 className="live-page-title">Bookings <PageRefreshButton label="Refresh bookings" disabled={loading || saving} onRefresh={refreshPageData} /></h1>{hasPermission('bookings', 'CREATE') && <button className="primary-button" type="button" onClick={openCreate}>New Booking</button>}</section>
      {message ? <div className="form-alert error">{message}</div> : null}
      <div className="booking-tabs"><button className={view === 'ACTIVE' ? 'active' : ''} type="button" onClick={() => changeView('ACTIVE')}>Active</button><button className={view === 'COMPLETED' ? 'active' : ''} type="button" onClick={() => changeView('COMPLETED')}>Completed</button><button className={view === 'OTHERS' ? 'active' : ''} type="button" onClick={() => changeView('OTHERS')}>Others</button></div>
      <section className="content-card booking-list-card">
        <div className="booking-filter-panel bookings-filter-panel-expanded">
          <label className="booking-filter-field">From<input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} /></label>
          <label className="booking-filter-field">To<input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} /></label>
          <label className="booking-filter-field">Booking ID<input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search booking ID" /></label>
          <label className="booking-filter-field">Customer Name<input type="search" value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Customer name" /></label>
          <label className="booking-filter-field">Service<SearchableSelect value={serviceFilter} onChange={(e) => setServiceFilter(e.target.value)}><option value="">All Services</option>{bootstrap.services.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="booking-filter-field">Source<SearchableSelect value={sourceFilter} onChange={(e) => setSourceFilter(e.target.value)}><option value="">All Sources</option><option value="RESERVATION">Reservation</option><option value="WALK_IN">Walk-In</option></SearchableSelect></label>
          <label className="booking-filter-field">Payment<SearchableSelect value={paymentFilter} onChange={(e) => setPaymentFilter(e.target.value)}><option value="">All Payments</option><option value="ADVANCE">Advance</option><option value="INTERIM">Interim</option><option value="CHECKOUT">Checkout Payment</option><option value="PAID">Paid</option><option value="ON_ACCOUNT">On Account</option><option value="SERVICE_CREDIT">Service Credit</option><option value="PAID_SERVICE_CREDIT">Paid Service Credit</option><option value="PENDING_CHECKOUT">Pending Checkout</option><option value="UNPAID">Unpaid</option></SearchableSelect></label>
          <div className="booking-filter-actions"><button type="button" className="secondary-button mini" onClick={resetFilters}>Reset Filters</button></div>
        </div>
        {loading ? <div className="empty-state">Loading bookings...</div> : <div className="table-wrap"><table className="data-table"><thead><tr><th>Booking</th><th>Schedule</th><th>Customer</th><th>Services</th><th>Source</th><th>Payment</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{bookings.map((booking) => <tr key={booking.id}><td><strong>{booking.code}</strong></td><td><strong>{formatDisplayDate(booking.booking_date)}</strong><span className="table-subline">{formatDisplayTime(booking.booking_time)}</span></td><td><strong>{booking.customer?.name}</strong> <CustomerSegmentTag customerClass={booking.customer?.customer_class} customerType={booking.customer?.customer_type || booking.customer?.segment} typeColor={booking.customer?.customer_type_color} /><span className="table-subline">{booking.customer?.mobile_number || '—'}</span></td><td><ServiceList services={booking.services} />{booking.retail_items.length ? <span className="table-subline">+ {booking.retail_items.length} retail item{booking.retail_items.length === 1 ? '' : 's'}</span> : null}</td><td>{booking.booking_source}</td><td><PaymentIndicator paymentStatus={booking.payment_status} paymentType={booking.payment_type} netAfterDiscountAmount={booking.net_after_discount_total} collectedReceivedAmount={['COMPLETED', 'OTHERS'].includes(view) ? booking.collected_received_total : null} advanceAmount={booking.advance_payment_total} interimAmount={booking.interim_payment_total} checkoutAmount={booking.checkout_payment_total} /></td><td><span className={`status-pill ${['CANCELLED','REVERSED'].includes(booking.status) ? 'danger' : ['BOOKED','COMPLETED'].includes(booking.status) ? 'success' : booking.status === 'CORRECTED' ? 'info' : 'neutral'}`}>{booking.status}</span></td><td className="action-cell"><ActionMenu items={[booking.can_check_in && hasPermission('bookings', 'EDIT') && hasPermission('queue', 'CREATE') ? { label: 'Check In', disabled: saving, onClick: () => setCheckInCandidate(booking) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(booking) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Rebook', onClick: () => setRebook({ id: booking.id, booking_date: booking.booking_date, booking_time: booking.booking_time, reason: '' }) } : null, booking.editable && hasPermission('bookings', 'EDIT') ? { label: 'Cancel', danger: true, onClick: () => setCancel({ id: booking.id, code: booking.code, reason: '' }) } : null]} /></td></tr>)}{!bookings.length && <tr><td colSpan="8"><div className="empty-state">No bookings in this view.</div></td></tr>}</tbody></table></div>}
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

      {form && <Modal title={form.id ? 'Edit Booking' : 'Add Booking'} subtitle="Reservations remain editable until check-in." onClose={() => setForm(null)} wide><form className="form-grid" onSubmit={save}><div className="form-field form-span-2 customer-picker-field"><div className="field-label-row"><span>Customer</span>{hasPermission('customers', 'CREATE') ? <button type="button" className="secondary-button mini" onClick={() => setQuickCustomerOpen(true)}>+ New Customer</button> : null}</div><SearchableSelect value={form.customer_id} onChange={(e) => selectCustomer(e.target.value)} required><option value="">Select customer</option>{bootstrap.customers.map((row) => <option key={row.id} value={row.id}>{row.name}{row.mobile_number ? ` · ${row.mobile_number}` : ''}</option>)}</SearchableSelect></div><label className="form-field">Customer Type<input value={bootstrap.customer_types?.find((row) => row.code === form.customer_type)?.value || form.customer_type || 'Unclassified'} readOnly /></label><label className="form-field">Booking Source<input value={form.booking_source === 'WALK_IN' ? 'Walk-In' : 'Reservation'} readOnly /></label><label className="form-field">Booking Date<input type="date" value={form.booking_date} onChange={(e) => changeBookingDate(e.target.value)} required /></label><label className="form-field">Booking Time<input type="time" value={form.booking_time} onChange={(e) => setForm((v) => ({ ...v, booking_time: e.target.value }))} required /></label><div className="section-box form-span-2"><div className="section-heading-row"><h3>Services</h3><button type="button" className="secondary-button mini" onClick={() => setForm((v) => ({ ...v, services: [...v.services, { ...emptyService }] }))}>+ Add Service</button></div>{form.services.map((line, index) => line.service_credit_id ? null : <div className="line-editor-grid" key={`service-${index}`}><label className="form-field">Service<SearchableSelect value={line.service_id ? `service:${line.service_id}` : ''} onChange={(e) => updateService(index, 'selection', e.target.value)}><option value="">Select service</option>{bootstrap.services.map((row) => <option key={row.id} value={`service:${row.id}`}>{serviceOptionLabel(row)}</option>)}</SearchableSelect></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} disabled={Boolean(line.service_credit_id) || isPackageService(bootstrap.services.find((service) => String(service.id) === String(line.service_id)))} onChange={(e) => updateService(index, 'quantity', e.target.value)} /></label><label className="form-field">Assigned Staff<SearchableSelect value={line.assigned_personnel_id} onChange={(e) => updateService(index, 'assigned_personnel_id', e.target.value)}><option value="">Unassigned</option>{bootstrap.personnel.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><button type="button" className="line-remove-button" onClick={() => setForm((v) => ({ ...v, services: v.services.filter((_, i) => i !== index) }))}>Remove</button></div>)}</div><ServiceCreditClaimSection customerSelected={Boolean(form.customer_id)} credits={visibleServiceCredits} selectedLines={form.services.filter((line) => line.service_credit_id)} personnel={bootstrap.personnel} services={bootstrap.services} onAdd={addServiceCreditClaim} onRemove={removeServiceCreditClaim} /><div className="section-box form-span-2"><div className="section-heading-row"><h3>Retail Purchases</h3><button type="button" className="secondary-button mini" onClick={() => setForm((v) => ({ ...v, retail_items: [...v.retail_items, { ...emptyRetail }] }))}>+ Add Retail</button></div>{form.retail_items.length ? form.retail_items.map((line, index) => <div className="line-editor-grid retail-line-grid" key={`retail-${index}`}><label className="form-field">Product<SearchableSelect value={line.retail_product_id || ""} onChange={(e) => updateRetail(index, 'product', e.target.value)}><option value="">{!line.retail_product_id && line.item_name ? `Legacy · ${line.item_name}` : 'Select product'}</option>{(bootstrap.retail_products || []).map((row) => <option key={row.id} value={row.id} disabled={Number(row.available || 0) <= 0 && String(line.retail_product_id || '') !== String(row.id)}>{row.sku ? `${row.sku} · ` : ''}{row.name} · {Number(row.available || 0) > 0 ? `Stock ${row.available} ${row.selling_uom || row.unit || ''}` : 'Out of stock'}</option>)}</SearchableSelect></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateRetail(index, 'quantity', e.target.value)} /></label><label className="form-field">Unit Price<input type="number" min="0" step="0.01" value={line.unit_price} readOnly /></label><label className="form-field">Assigned Staff<SearchableSelect value={line.assigned_personnel_id || ''} onChange={(e) => updateRetail(index, 'assigned_personnel_id', e.target.value)}><option value="">Assign later</option>{(bootstrap.retail_personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><button type="button" className="line-remove-button" onClick={() => setForm((v) => ({ ...v, retail_items: v.retail_items.filter((_, i) => i !== index) }))}>Remove</button></div>) : <div className="inline-empty">No retail purchases added.</div>}</div>{!form.id && bootstrap.can_receive_advance ? <InitialPaymentCollection collection={form.payment_collection} setCollection={(updater) => setForm((current) => ({ ...current, payment_collection: typeof updater === 'function' ? updater(current.payment_collection) : updater }))} paymentMethods={bootstrap.payment_methods} onAccountData={onAccountData} contextLabel="booking" allowCurrentAdvance={hasCurrentAdvanceTarget(form)} /> : null}<label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => setForm((v) => ({ ...v, notes: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Booking'}</button></div></form></Modal>}

      {rebook && <Modal title="Rebook Appointment" subtitle="The same Booking ID is retained and the old schedule is recorded in append-only history." onClose={() => setRebook(null)}><form className="form-grid" onSubmit={saveRebook}><label className="form-field">New Date<input type="date" value={rebook.booking_date} onChange={(e) => setRebook((v) => ({ ...v, booking_date: e.target.value }))} required /></label><label className="form-field">New Time<input type="time" value={rebook.booking_time} onChange={(e) => setRebook((v) => ({ ...v, booking_time: e.target.value }))} required /></label><label className="form-field form-span-2">Reason / Notes<textarea rows="3" value={rebook.reason} onChange={(e) => setRebook((v) => ({ ...v, reason: e.target.value }))} /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setRebook(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>Save Rebooking</button></div></form></Modal>}

      {cancel && <Modal title={`Cancel ${cancel.code}`} subtitle="This affects only a reservation that has not entered Queuing." onClose={() => setCancel(null)}><form className="form-grid" onSubmit={saveCancel}><label className="form-field form-span-2">Cancellation Reason<textarea rows="4" value={cancel.reason} onChange={(e) => setCancel((v) => ({ ...v, reason: e.target.value }))} required /></label><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancel(null)}>Keep Booking</button><button className="danger-button" type="submit" disabled={saving}>Cancel Booking</button></div></form></Modal>}
      {quickCustomerOpen ? <QuickCustomerModal onClose={() => setQuickCustomerOpen(false)} onCreated={handleQuickCustomerCreated} /> : null}

    </div>
  )
}
