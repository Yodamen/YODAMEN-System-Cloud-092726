import SearchableSelect from '../common/SearchableSelect'
import { formatPeso } from '../../utils/formatters'
import SupportingDocumentField from '../common/SupportingDocumentField'
import { paymentMethodRequiresProof } from '../../utils/supportingDocuments'

function allocationTotal(allocations = {}) {
  return Object.values(allocations).reduce((sum, value) => sum + Number(value || 0), 0)
}

export default function InitialPaymentCollection({ collection, setCollection, paymentMethods = [], onAccountData = null, contextLabel = 'booking', allowCurrentAdvance = true }) {
  const hasOnAccount = Number(onAccountData?.outstanding_balance || 0) > 0
  const received = Number(collection?.amount || 0)
  const interimTotal = allocationTotal(collection?.allocations)
  const advanceAmount = allowCurrentAdvance
    ? (hasOnAccount ? Number(collection?.advance_amount || 0) : received)
    : 0
  const allocated = advanceAmount + interimTotal
  const unallocated = Math.max(0, received - allocated)
  const overallocated = Math.max(0, allocated - received)

  function patch(values) {
    setCollection((current) => ({ ...current, ...values }))
  }

  function setReceived(value) {
    setCollection((current) => ({
      ...current,
      amount: value,
      advance_amount: allowCurrentAdvance
        ? (hasOnAccount ? current.advance_amount : value)
        : '',
    }))
  }

  function setAllocation(allocationKey, value) {
    setCollection((current) => ({
      ...current,
      allocations: { ...(current.allocations || {}), [allocationKey]: value },
    }))
  }

  const helper = contextLabel === 'walk-in'
    ? 'Use only when money was collected before the final checkout step.'
    : 'Record money received before final checkout. This does not complete the service or recognize the sale.'

  const paymentProofRequired = Boolean(collection?.collected)
    && paymentMethodRequiresProof(collection?.payment_method_reference_id, paymentMethods)

  return (
    <section className="transaction-form-card form-span-2">
      <div className="transaction-section-title advance-section-title">
        <div>
          <h3>Advance Payment</h3>
          <p>{helper}</p>
        </div>
        <label className="transaction-advance-check">
          <input type="checkbox" checked={collection?.collected || false} onChange={(event) => patch({ collected: event.target.checked })} />
          <span>Advance Payment Collected?</span>
        </label>
      </div>

      {collection?.collected ? (
        <div className="advance-payment-content">
          <div className="transaction-section-grid two-columns advance-basic-grid">
            <label className="form-field"><span>Amount Collected *</span><input type="number" min="0.01" step="0.01" required value={collection?.amount || ''} onChange={(event) => setReceived(event.target.value)} placeholder="0.00" /></label>
            <label className="form-field"><span>Payment Method *</span><SearchableSelect required value={collection?.payment_method_reference_id || ''} onChange={(event) => patch({ payment_method_reference_id: event.target.value })}><option value="">Select method</option>{paymentMethods.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect>{!paymentMethods.length ? <small>Add an active Payment Method under Reference Lists first.</small> : null}</label>
            <label className="form-field"><span>Reference No.</span><input value={collection?.reference_no || ''} onChange={(event) => patch({ reference_no: event.target.value })} placeholder="Optional" /></label>
          </div>

          {paymentProofRequired ? (
            <div className="form-span-2">
              <SupportingDocumentField
                required
                files={collection?.supporting_files || []}
                onChange={(files) => patch({ supporting_files: files })}
                label="Payment Proof"
                hint="Required for non-cash payments such as GCash, bank transfer, e-wallet or card. Cash payments do not require proof."
              />
            </div>
          ) : null}

          {hasOnAccount ? (
            <div className="split-allocation-panel appscript-split-allocation">
              <div className="split-allocation-summary">
                <div><span>Received</span><strong>{formatPeso(received)}</strong></div>
                <div><span>Allocated</span><strong>{formatPeso(allocated)}</strong></div>
                <div className={overallocated > 0 ? 'danger-text' : ''}><span>{overallocated > 0 ? 'Overallocated' : 'Unallocated'}</span><strong>{formatPeso(overallocated > 0 ? overallocated : unallocated)}</strong></div>
              </div>

              {allowCurrentAdvance ? (
                <div className="split-current-booking">
                  <div>
                    <strong>New {contextLabel === 'walk-in' ? 'Walk-In' : 'Booking'}</strong>
                    <span>Reserve part of this receipt as Advance for the new transaction.</span>
                  </div>
                  <label><span>Advance</span><input type="number" min="0" max={received || undefined} step="0.01" value={collection?.advance_amount || ''} onChange={(event) => patch({ advance_amount: event.target.value })} placeholder="0.00" /></label>
                </div>
              ) : null}

              <div className="interim-allocation-panel">
                <div className="interim-allocation-heading"><div><strong>Outstanding Customer Balances</strong><span>Apply any Interim portion of this same receipt to older On Account lines or opening balances.</span></div><div><span>Open Balance</span><strong>{formatPeso(onAccountData.outstanding_balance)}</strong></div></div>
                {(onAccountData?.bookings || []).map((booking) => (
                  <div className="interim-booking-group" key={`${booking.booking_source || 'BOOKING'}-${booking.booking_id}`}>
                    <div className="interim-booking-title"><strong>{booking.booking_code}</strong><span>{formatPeso(booking.outstanding_balance)} balance</span></div>
                    {(booking.items || []).map((item) => (
                      <div className="interim-line" key={item.allocation_key || `sale:${item.id}`}>
                        <div><strong>{item.item_name}</strong><span>{formatPeso(item.balance_amount)} outstanding</span></div>
                        <label><span>Interim</span><input type="number" min="0" max={item.balance_amount} step="0.01" value={collection?.allocations?.[item.allocation_key || `sale:${item.id}`] || ''} onChange={(event) => setAllocation(item.allocation_key || `sale:${item.id}`, event.target.value)} placeholder="0.00" /></label>
                      </div>
                    ))}
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      ) : null}
    </section>
  )
}
