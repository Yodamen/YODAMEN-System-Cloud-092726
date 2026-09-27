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

const emptyWalkIn = () => ({ customer_id: '', customer_type: '', notes: '', services: [{ ...emptyService }], retail_items: [], payment_collection: emptyPaymentCollection() })

export default function QueuePage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ customers: [], services: [], retail_products: [], personnel: [], retail_personnel: [], customer_types: [], payment_methods: [], can_receive_advance: false, phase_notes: {} })
  const [rows, setRows] = useState([])
  const [walkIn, setWalkIn] = useState(null)
  const [cancel, setCancel] = useState(null)
  const [quickCustomerOpen, setQuickCustomerOpen] = useState(false)
  const [onAccountData, setOnAccountData] = useState(null)
  const [serviceCredits, setServiceCredits] = useState([])
  const [startCandidate, setStartCandidate] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/operations/queue/bootstrap')
    setBootstrap(response.data)
    return response.data
  }, [])

  const loadQueue = useCallback(async () => {
    const response = await api.get('/v1/operations/queue', { params: { status: 'WAITING' } })
    setRows(response.data.queue || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadBootstrap(), loadQueue()])
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load queue.')))
        .finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [loadBootstrap, loadQueue])

  async function refreshPageData() {
    setMessage('')
    try {
      await Promise.all([loadBootstrap(), loadQueue()])
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh queue.'))
    }
  }

  const canCreateWalkIn = hasPermission('queue', 'CREATE') && hasPermission('bookings', 'CREATE')
  const canEditQueue = hasPermission('queue', 'EDIT')

  function updateService(index, key, value) {
    setWalkIn((current) => ({
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
    setWalkIn((current) => ({
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

    setWalkIn((current) => current ? { ...current, services: [...current.services, ...claimedLines] } : current)
  }

  function removeServiceCreditClaim(serviceCreditId, assignedPersonnelId) {
    setWalkIn((current) => current ? {
      ...current,
      services: current.services.filter((line) => !(
        String(line.service_credit_id || '') === String(serviceCreditId)
        && String(line.assigned_personnel_id || '') === String(assignedPersonnelId || '')
      )),
    } : current)
  }

  async function selectWalkInCustomer(customerId) {
    const selectedCustomer = bootstrap.customers.find((row) => String(row.id) === String(customerId))
    setWalkIn((current) => current ? {
      ...current,
      customer_id: customerId,
      customer_type: selectedCustomer?.customer_type || '',
      services: current.services.filter((line) => !line.service_credit_id),
      payment_collection: emptyPaymentCollection(),
    } : current)
    setOnAccountData(null)
    setServiceCredits([])
    if (!customerId) return

    const [onAccountResult, creditResult] = await Promise.allSettled([
      api.get(`/v1/sales/on-account/customers/${customerId}`),
      api.get(`/v1/operations/customers/${customerId}/service-credits`),
    ])

    setOnAccountData(onAccountResult.status === 'fulfilled' ? onAccountResult.value.data : null)
    setServiceCredits(creditResult.status === 'fulfilled' ? (creditResult.value.data.service_credits || []) : [])
  }

  async function handleQuickCustomerCreated(customer) {
    const nextBootstrap = await loadBootstrap()
    if (!nextBootstrap.customers?.some((row) => row.id === customer.id)) {
      const option = { id: customer.id, code: customer.code, name: customer.full_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim(), mobile_number: customer.mobile_number }
      setBootstrap((current) => ({ ...current, customers: [...current.customers, option] }))
    }
    await selectWalkInCustomer(String(customer.id))
  }

  async function saveWalkIn(event) {
    event.preventDefault()
    setMessage('')
    const stockError = retailStockError(walkIn?.retail_items, bootstrap.retail_products)
    if (stockError) {
      setMessage(stockError)
      return
    }

    setSaving(true)
    try {
      const payload = {
        customer_id: Number(walkIn.customer_id),
        notes: walkIn.notes,
        services: walkIn.services.filter((line) => line.service_id).map((line) => ({
          service_id: Number(line.service_id),
          service_credit_id: line.service_credit_id ? Number(line.service_credit_id) : null,
          quantity: Number(line.quantity || 1),
          assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null,
          notes: line.notes,
        })),
        retail_items: walkIn.retail_items.filter((line) => line.retail_product_id || line.item_name.trim()).map((line) => ({
          retail_product_id: line.retail_product_id ? Number(line.retail_product_id) : null,
          item_name: line.item_name,
          quantity: Number(line.quantity || 1),
          unit_price: Number(line.unit_price || 0),
          assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null,
          notes: line.notes,
        })),
      }
      if (walkIn.payment_collection?.collected) {
        const proofRequired = paymentMethodRequiresProof(walkIn.payment_collection.payment_method_reference_id, bootstrap.payment_methods)
        if (proofRequired && !(walkIn.payment_collection.supporting_files || []).length) {
          throw new Error('Payment proof is required for non-cash Walk-In payments such as GCash or bank transfer.')
        }
        const attachmentTokens = await uploadSupportingDocuments(
          walkIn.payment_collection.supporting_files || [],
          'bookings',
          'BOOKING_PAYMENT_PROOF',
        )
        payload.payment_collection = {
          collected: true,
          amount: Number(walkIn.payment_collection.amount || 0),
          advance_amount: onAccountData?.outstanding_balance > 0 ? Number(walkIn.payment_collection.advance_amount || 0) : Number(walkIn.payment_collection.amount || 0),
          payment_method_reference_id: walkIn.payment_collection.payment_method_reference_id ? Number(walkIn.payment_collection.payment_method_reference_id) : null,
          reference_no: walkIn.payment_collection.reference_no || '',
          attachment_tokens: attachmentTokens,
          allocations: Object.entries(walkIn.payment_collection.allocations || {})
            .map(([allocationKey, value]) => {
              const amount = Number(value || 0)
              if (allocationKey.startsWith('opening:')) return { opening_receivable_id: Number(allocationKey.slice(8)), amount }
              const saleItemId = allocationKey.startsWith('sale:') ? allocationKey.slice(5) : allocationKey
              return { sale_item_id: Number(saleItemId), amount }
            })
            .filter((row) => row.amount > 0),
        }
      }
      await api.post('/v1/operations/queue/walk-ins', payload)
      setWalkIn(null)
      await loadQueue()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to check in Walk-In.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveCancel(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/queue/${cancel.id}/cancel`, { reason: cancel.reason })
      setCancel(null)
      await loadQueue()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to cancel queue entry.'))
    } finally {
      setSaving(false)
    }
  }

  async function startServicing(row) {
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/queue/${row.id}/start-servicing`)
      await loadQueue()
      setStartCandidate(null)
      setMessage(`${row.customer?.name || row.booking?.code || 'Customer'} is now in Servicing.`)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to start servicing.'))
    } finally {
      setSaving(false)
    }
  }

  const visibleServiceCredits = walkIn ? adjustedServiceCredits(serviceCredits, walkIn.services) : serviceCredits

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Queuing <PageRefreshButton label="Refresh queuing" disabled={loading || saving} onRefresh={refreshPageData} /></h1>
        {canCreateWalkIn && <button className="primary-button" type="button" onClick={() => { setMessage(''); setOnAccountData(null); setServiceCredits([]); setWalkIn(emptyWalkIn()) }}>New Walk-In</button>}
      </section>

      {message ? <div className={`form-alert ${message.includes('now in Servicing') ? 'success' : 'error'}`}>{message}</div> : null}

      <section className="content-card live-operations-card">
        {loading ? <div className="empty-state live-empty-state">Loading queue...</div> : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th className="queue-priority-column">#</th><th>Booking ID</th><th>Customer</th><th>Services</th><th>Payment</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="queue-priority-cell">{row.priority_no || '—'}</td>
                    <td><strong>{row.booking?.code || '—'}</strong><span className="table-subline">Checked in {formatDisplayTime(row.check_in_time)}</span>{row.source === 'RESERVATION' && row.scheduled_date ? <span className="table-subline">Scheduled {formatDisplayDate(row.scheduled_date)} · {formatDisplayTime(row.scheduled_time)}</span> : null}</td>
                    <td><strong>{row.customer?.name}</strong> <CustomerSegmentTag customerClass={row.customer?.customer_class} customerType={row.customer?.customer_type || row.customer?.segment} typeColor={row.customer?.customer_type_color} /><span className="table-subline">{row.source === 'WALK_IN' ? 'Walk-In' : 'Reservation'}</span></td>
                    <td><ServiceList services={row.services} showStaff />{!row.services.length && row.retail_items.length ? <span className="table-subline">{row.retail_items.length} retail item{row.retail_items.length === 1 ? '' : 's'}</span> : null}</td>
                    <td><PaymentIndicator advanceAmount={row.advance_payment_total} interimAmount={row.interim_payment_total} /></td>
                    <td><span className={`status-pill ${['CANCELLED','REVERSED'].includes(row.status) ? 'danger' : row.status === 'COMPLETED' ? 'success' : ['WAITING','SERVICING','IN_PROGRESS'].includes(row.status) ? 'info' : 'neutral'}`}>{row.status}</span></td>
                    <td className="action-cell"><ActionMenu items={[
                      row.can_start_servicing && canEditQueue ? { label: 'Start Servicing', disabled: saving, onClick: () => setStartCandidate(row) } : null,
                      row.can_cancel && canEditQueue ? { label: 'Cancel', danger: true, onClick: () => setCancel({ id: row.id, booking_code: row.booking?.code || 'Booking', reason: '' }) } : null,
                    ]} /></td>
                  </tr>
                ))}
                {!rows.length && <tr><td colSpan="7"><div className="empty-state live-empty-state">No customers in queue right now.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {startCandidate && (
        <ConfirmationModal
          customerName={startCandidate.customer?.name}
          bookingCode={startCandidate.booking?.code}
          services={startCandidate.services}
          cancelLabel="Cancel"
          confirmLabel="Start Servicing"
          busy={saving}
          onCancel={() => setStartCandidate(null)}
          onConfirm={() => startServicing(startCandidate)}
        />
      )}

      {walkIn && (
        <Modal title="New Walk-In" subtitle="Creates one Booking and one Queue entry in the same database transaction." onClose={() => setWalkIn(null)} wide>
          <form className="form-grid" onSubmit={saveWalkIn}>
            <div className="form-field form-span-2 customer-picker-field"><div className="field-label-row"><span>Customer</span>{hasPermission('customers', 'CREATE') ? <button type="button" className="secondary-button mini" onClick={() => setQuickCustomerOpen(true)}>+ New Customer</button> : null}</div><SearchableSelect required value={walkIn.customer_id} onChange={(e) => selectWalkInCustomer(e.target.value)}><option value="">Select customer</option>{bootstrap.customers.map((row) => <option key={row.id} value={row.id}>{row.name}{row.mobile_number ? ` · ${row.mobile_number}` : ''}</option>)}</SearchableSelect></div>
            <label className="form-field">Customer Type<input value={bootstrap.customer_types.find((row) => row.code === walkIn.customer_type)?.value || walkIn.customer_type || 'Unclassified'} readOnly /><small>Automatically detected from completed visit history.</small></label>
            <label className="form-field">Source<input value="Walk-In" disabled /></label>
            
            <div className="section-box form-span-2">
              <div className="section-heading-row"><h3>Services</h3><button type="button" className="secondary-button mini" onClick={() => setWalkIn((v) => ({ ...v, services: [...v.services, { ...emptyService }] }))}>+ Add Service</button></div>
              {walkIn.services.map((line, index) => line.service_credit_id ? null : <div className="line-editor-grid" key={`walk-service-${index}`}><label className="form-field">Service<SearchableSelect value={line.service_id ? `service:${line.service_id}` : ''} onChange={(e) => updateService(index, 'selection', e.target.value)}><option value="">Select service</option>{bootstrap.services.map((row) => <option key={row.id} value={`service:${row.id}`}>{serviceOptionLabel(row)}</option>)}</SearchableSelect></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} disabled={Boolean(line.service_credit_id) || isPackageService(bootstrap.services.find((service) => String(service.id) === String(line.service_id)))} onChange={(e) => updateService(index, 'quantity', e.target.value)} /></label><label className="form-field">Assigned Staff<SearchableSelect value={line.assigned_personnel_id} onChange={(e) => updateService(index, 'assigned_personnel_id', e.target.value)}><option value="">Unassigned</option>{bootstrap.personnel.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><button type="button" className="line-remove-button" onClick={() => setWalkIn((v) => ({ ...v, services: v.services.filter((_, i) => i !== index) }))}>Remove</button></div>)}
            </div>
            <ServiceCreditClaimSection customerSelected={Boolean(walkIn.customer_id)} credits={visibleServiceCredits} selectedLines={walkIn.services.filter((line) => line.service_credit_id)} personnel={bootstrap.personnel} services={bootstrap.services} onAdd={addServiceCreditClaim} onRemove={removeServiceCreditClaim} />
            <div className="section-box form-span-2">
              <div className="section-heading-row"><h3>Retail Purchases</h3><button type="button" className="secondary-button mini" onClick={() => setWalkIn((v) => ({ ...v, retail_items: [...v.retail_items, { ...emptyRetail }] }))}>+ Add Retail</button></div>
              {walkIn.retail_items.length ? walkIn.retail_items.map((line, index) => <div className="line-editor-grid retail-line-grid" key={`walk-retail-${index}`}><label className="form-field">Product<SearchableSelect value={line.retail_product_id || ""} onChange={(e) => updateRetail(index, 'product', e.target.value)}><option value="">Select product</option>{(bootstrap.retail_products || []).map((row) => <option key={row.id} value={row.id} disabled={Number(row.available || 0) <= 0 && String(line.retail_product_id || '') !== String(row.id)}>{row.sku} · {row.name} · ₱{Number(row.selling_price || 0).toLocaleString()} · {Number(row.available || 0) > 0 ? `Stock ${row.available} ${row.selling_uom || row.unit || ''}` : 'Out of stock'}</option>)}</SearchableSelect></label><label className="form-field">Qty<input type="number" min="1" value={line.quantity} onChange={(e) => updateRetail(index, 'quantity', e.target.value)} /></label><label className="form-field">Unit Price<input type="number" min="0" step="0.01" value={line.unit_price} readOnly /></label><label className="form-field">Assigned Staff<SearchableSelect value={line.assigned_personnel_id || ''} onChange={(e) => updateRetail(index, 'assigned_personnel_id', e.target.value)}><option value="">Assign later</option>{(bootstrap.retail_personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><button type="button" className="line-remove-button" onClick={() => setWalkIn((v) => ({ ...v, retail_items: v.retail_items.filter((_, i) => i !== index) }))}>Remove</button></div>) : <div className="inline-empty">No retail purchases added.</div>}
            </div>
            {bootstrap.can_receive_advance ? <InitialPaymentCollection collection={walkIn.payment_collection} setCollection={(updater) => setWalkIn((current) => ({ ...current, payment_collection: typeof updater === 'function' ? updater(current.payment_collection) : updater }))} paymentMethods={bootstrap.payment_methods} onAccountData={onAccountData} contextLabel="walk-in" /> : null}<label className="form-field form-span-2">Notes<textarea rows="3" value={walkIn.notes} onChange={(e) => setWalkIn((v) => ({ ...v, notes: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setWalkIn(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Checking In...' : 'Check In Walk-In'}</button></div>
          </form>
        </Modal>
      )}

      {quickCustomerOpen ? <QuickCustomerModal onClose={() => setQuickCustomerOpen(false)} onCreated={handleQuickCustomerCreated} /> : null}

      {cancel && (
        <Modal title={`Cancel ${cancel.booking_code}`} subtitle="The Queue record and linked Booking remain in history and are marked Cancelled." onClose={() => setCancel(null)}>
          <form className="form-grid" onSubmit={saveCancel}>
            <label className="form-field form-span-2">Cancellation Reason<textarea rows="4" required value={cancel.reason} onChange={(e) => setCancel((v) => ({ ...v, reason: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancel(null)}>Keep Waiting</button><button className="danger-button" type="submit" disabled={saving}>Cancel Queue Entry</button></div>
          </form>
        </Modal>
      )}
    </div>
  )
}
