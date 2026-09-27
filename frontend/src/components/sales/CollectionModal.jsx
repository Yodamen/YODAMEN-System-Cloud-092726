import SearchableSelect from '../common/SearchableSelect'
import { useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function newIdempotencyKey() {
  const suffix = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
  return `collection-${suffix}`
}

function num(value) {
  const parsed = Number(value || 0)
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}

export default function CollectionModal({ sale, items, paymentMethods, onClose, onCollected }) {
  const [amountCollected, setAmountCollected] = useState('')
  const [paymentMethodId, setPaymentMethodId] = useState('')
  const [referenceNo, setReferenceNo] = useState('')
  const [allocations, setAllocations] = useState(() => Object.fromEntries((items || []).map((item) => [item.id, ''])))
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const allocated = useMemo(() => Object.values(allocations).reduce((sum, value) => sum + num(value), 0), [allocations])

  async function submit() {
    setSaving(true)
    setMessage('')
    try {
      const response = await api.post(`/v1/sales/transactions/${sale.id}/collections`, {
        idempotency_key: newIdempotencyKey(),
        amount_collected: num(amountCollected),
        payment_method_reference_id: Number(paymentMethodId || 0),
        reference_no: referenceNo || null,
        allocations: items.map((item) => ({ sale_item_id: item.id, amount: num(allocations[item.id]) })),
      })
      onCollected(response.data.sale)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to record collection.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={sale.customer?.name || 'On Account Collection'} subtitle={`Booking ID: ${sale.booking?.code || '—'} · ${formatDisplayDate(sale.sale_date)}`} onClose={onClose} wide>
      <div className="checkout-summary-grid collection-summary-grid">
        <div className="summary-card"><span>Outstanding Balance</span><strong>{formatPeso(sale.outstanding_balance)}</strong></div>
        <div className="summary-card"><span>Amount Collected</span><strong>{formatPeso(num(amountCollected))}</strong></div>
        <div className="summary-card"><span>Allocated</span><strong>{formatPeso(allocated)}</strong></div>
      </div>
      {message ? <div className="form-alert error">{message}</div> : null}
      <div className="section-box">
        <div className="section-heading-row"><h3>Allocate Collection</h3></div>
        <div className="table-wrap"><table className="data-table collection-allocation-table"><thead><tr><th>Service / Item</th><th className="money-column">Balance</th><th className="money-column">Allocation</th></tr></thead><tbody>{items.map((item) => <tr key={item.id}><td><strong>{item.item_name}</strong></td><td className="money-cell">{formatPeso(item.balance_amount)}</td><td><input className="allocation-input" type="number" min="0" step="0.01" value={allocations[item.id]} onChange={(event) => setAllocations((current) => ({ ...current, [item.id]: event.target.value }))} placeholder="0.00" /></td></tr>)}</tbody></table></div>
      </div>
      <div className="section-box checkout-payment-section">
        <div className="checkout-payment-grid">
          <label>Amount Collected<input type="number" min="0" step="0.01" value={amountCollected} onChange={(event) => setAmountCollected(event.target.value)} placeholder="0.00" /></label>
          <label>Payment Method<SearchableSelect value={paymentMethodId} onChange={(event) => setPaymentMethodId(event.target.value)}><option value="">Select method</option>{paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}</SearchableSelect></label>
          <label>Reference No.<input value={referenceNo} onChange={(event) => setReferenceNo(event.target.value)} placeholder="Optional" /></label>
        </div>
      </div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" type="button" onClick={submit} disabled={saving}>{saving ? 'Recording...' : 'Record Collection'}</button></div>
    </Modal>
  )
}
