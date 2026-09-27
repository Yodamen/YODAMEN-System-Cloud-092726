import SearchableSelect from '../common/SearchableSelect'
import { useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function key() {
  const suffix = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
  return `interim-${suffix}`
}

function money(value) {
  const parsed = Number(value || 0)
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}

export default function InterimPaymentModal({ customers, initialCustomerId = '', onClose, onRecorded }) {
  const [customerId, setCustomerId] = useState(initialCustomerId ? String(initialCustomerId) : '')
  const [detail, setDetail] = useState(null)
  const [allocations, setAllocations] = useState({})
  const [methodId, setMethodId] = useState('')
  const [referenceNo, setReferenceNo] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false

    const timeoutId = window.setTimeout(() => {
      if (!customerId) {
        setDetail(null)
        setAllocations({})
        setLoading(false)
        setMessage('')
        return
      }

      setLoading(true)
      setMessage('')

      api.get(`/v1/sales/on-account/customers/${customerId}`)
        .then((response) => {
          if (cancelled) return

          setDetail(response.data)
          const map = {}
          for (const booking of response.data.bookings || []) {
            for (const item of booking.items || []) map[item.id] = ''
          }
          setAllocations(map)
        })
        .catch((error) => {
          if (!cancelled) {
            setDetail(null)
            setAllocations({})
            setMessage(getApiErrorMessage(error, 'Unable to load On Account bookings.'))
          }
        })
        .finally(() => {
          if (!cancelled) setLoading(false)
        })
    }, 0)

    return () => {
      cancelled = true
      window.clearTimeout(timeoutId)
    }
  }, [customerId])

  const total = useMemo(() => Object.values(allocations).reduce((sum, value) => sum + money(value), 0), [allocations])

  async function submit() {
    if (!customerId) return
    setSaving(true)
    setMessage('')
    try {
      const payload = {
        idempotency_key: key(),
        payment_method_reference_id: Number(methodId || 0),
        reference_no: referenceNo || null,
        allocations: Object.entries(allocations).map(([saleItemId, value]) => ({
          sale_item_id: Number(saleItemId),
          amount: money(value),
        })),
      }
      const response = await api.post(`/v1/sales/on-account/customers/${customerId}/interim-payments`, payload)
      onRecorded(response.data.payment)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to record Interim Payment.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Receive Interim Payment" subtitle="Apply one customer payment to open On Account bookings." onClose={onClose} wide>
      <div className="interim-customer-picker">
        <label>
          Customer
          <SearchableSelect value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
            <option value="">Select customer</option>
            {(customers || []).map((customer) => (
              <option key={customer.id} value={customer.id}>
                {customer.name} · {formatPeso(customer.outstanding_balance)} outstanding
              </option>
            ))}
          </SearchableSelect>
        </label>
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}
      {loading ? <div className="empty-state compact">Loading open bookings...</div> : null}

      {detail ? (
        <>
          <div className="interim-customer-summary">
            <div>
              <span>Customer</span>
              <strong>{detail.customer?.name}</strong>
              <small>{detail.customer?.mobile_number || detail.customer?.code}</small>
            </div>
            <div>
              <span>Total On Account</span>
              <strong>{formatPeso(detail.outstanding_balance)}</strong>
              <small>{detail.bookings?.length || 0} open booking{detail.bookings?.length === 1 ? '' : 's'}</small>
            </div>
            <div>
              <span>Interim Payment</span>
              <strong>{formatPeso(total)}</strong>
              <small>Based on allocations below</small>
            </div>
          </div>

          <div className="interim-bookings-list">
            {(detail.bookings || []).map((booking) => (
              <section className="interim-booking-card" key={booking.sale_id}>
                <header>
                  <div>
                    <strong>{booking.booking_code || 'Booking'}</strong>
                    <span>{formatDisplayDate(booking.transaction_date)} · {String(booking.booking_source || '').replaceAll('_', ' ')}</span>
                  </div>
                  <div className="interim-booking-balance">
                    <span>Balance</span>
                    <strong>{formatPeso(booking.outstanding_balance)}</strong>
                  </div>
                </header>
                <div className="interim-line-list">
                  {(booking.items || []).map((item) => (
                    <div className="interim-line-row" key={item.id}>
                      <div>
                        <strong>{item.item_name}</strong>
                        {item.assigned_personnel_name ? <span>{item.assigned_personnel_name}</span> : null}
                      </div>
                      <span className="interim-line-balance">{formatPeso(item.balance_amount)}</span>
                      <label>
                        Apply Payment
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={allocations[item.id] ?? ''}
                          onChange={(event) => setAllocations((current) => ({ ...current, [item.id]: event.target.value }))}
                          placeholder="0.00"
                        />
                      </label>
                    </div>
                  ))}
                </div>
              </section>
            ))}
          </div>

          <div className="interim-payment-details">
            <div className="interim-payment-total">
              <span>Payment to Receive</span>
              <strong>{formatPeso(total)}</strong>
            </div>
            <label>
              Payment Method
              <SearchableSelect value={methodId} onChange={(event) => setMethodId(event.target.value)} disabled={total <= 0}>
                <option value="">Select method</option>
                {(detail.payment_methods || []).map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
              </SearchableSelect>
            </label>
            <label>
              Reference No.
              <input value={referenceNo} onChange={(event) => setReferenceNo(event.target.value)} placeholder="Optional" disabled={total <= 0} />
            </label>
          </div>
        </>
      ) : null}

      <div className="modal-actions">
        <button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="primary-button" type="button" onClick={submit} disabled={saving || loading || !customerId || total <= 0}>
          {saving ? 'Recording...' : 'Record Interim Payment'}
        </button>
      </div>
    </Modal>
  )
}
