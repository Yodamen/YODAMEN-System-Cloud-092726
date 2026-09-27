import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import SupportingDocumentList from '../../components/common/SupportingDocumentList'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './inventory-control.css'

function dateValue(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
function monthStart() { const d = new Date(); return dateValue(new Date(d.getFullYear(), d.getMonth(), 1)) }
function today() { return dateValue(new Date()) }
function displayDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  const months = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.']
  return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`
}

const emptyLine = {
  retail_product_id: '',
  purchase_quantity: '1',
  purchase_uom: '',
  conversion_factor: '1',
  purchase_unit_cost: '0',
  basic_uom: '',
}

const emptyPayment = { funding_account_id: '', amount: '', reference: '' }

function convertedBasicQty(line) {
  return Number(line.purchase_quantity || 0) * Number(line.conversion_factor || 0)
}

function lineTotal(line) {
  return Number(line.purchase_quantity || 0) * Number(line.purchase_unit_cost || 0)
}

function paymentLabel(split) {
  return split.funding_source || split.payment_method || split.cash_account || '—'
}

export default function StockReceivedPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ options: { branches: [], products: [], suppliers: [], uoms: [], funding_sources: [] }, receipts: [] })
  const [filters, setFilters] = useState({ branch_id: '', supplier_id: '', product_id: '', from: monthStart(), to: today(), search: '' })
  const [form, setForm] = useState(null)
  const [selected, setSelected] = useState(null)
  const [correction, setCorrection] = useState(null)
  const [reversing, setReversing] = useState(null)
  const [actionReview, setActionReview] = useState(null)
  const [actionApproval, setActionApproval] = useState(null)
  const [correctionRefund, setCorrectionRefund] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const canRequestCorrection = hasPermission('stock-received', 'REQUEST_CORRECTION')
  const canApproveCorrection = hasPermission('stock-received', 'APPROVE_CORRECTION')
  const canRequestReversal = hasPermission('stock-received', 'REQUEST_REVERSAL')
  const canApproveReversal = hasPermission('stock-received', 'APPROVE_REVERSAL')
  const canReceiveCorrectionRefund = hasPermission('stock-received', 'RECEIVE_REFUND')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/inventory/receipts', { params: { ...next, branch_id: next.branch_id || undefined, supplier_id: next.supplier_id || undefined, product_id: next.product_id || undefined, search: next.search || undefined } })
    setData(response.data)
    if (!next.branch_id && response.data.options?.branches?.[0]?.id) setFilters((current) => ({ ...current, branch_id: String(response.data.options.branches[0].id) }))
  }, [filters])

  useEffect(() => {
    const timer = window.setTimeout(() => load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load stock receipts.'))).finally(() => setLoading(false)), 0)
    return () => window.clearTimeout(timer)
  }, [])

  const supplierProducts = useMemo(() => {
    if (!form?.supplier_id) return data.options?.products || []
    const supplier = (data.options?.suppliers || []).find((row) => String(row.id) === String(form.supplier_id))
    if (!supplier) return []
    const allowed = new Set((supplier.item_ids || []).map(String))
    return (data.options?.products || []).filter((product) => allowed.has(String(product.id)))
  }, [data.options?.products, data.options?.suppliers, form?.supplier_id])

  const formTotal = useMemo(() => (form?.lines || []).reduce((sum, line) => sum + lineTotal(line), 0), [form])
  const grandTotal = formTotal + Number(form?.delivery_fee || 0)
  const paymentTotal = useMemo(() => (form?.payment_splits || []).reduce((sum, split) => sum + Number(split.amount || 0), 0), [form?.payment_splits])
  const balance = Math.max(0, grandTotal - paymentTotal)

  function openReceive() {
    setMessage('')
    setForm({
      branch_id: filters.branch_id || String(data.options?.branches?.[0]?.id || ''),
      received_date: today(),
      supplier_id: '',
      reference_no: '',
      delivery_fee: '0',
      payment_status: 'UNPAID',
      payment_splits: [{ ...emptyPayment }],
      notes: '',
      supporting_files: [],
      lines: [{ ...emptyLine }],
    })
  }

  function patchLine(index, patch) {
    setForm((current) => ({ ...current, lines: current.lines.map((line, i) => i === index ? { ...line, ...patch } : line) }))
  }

  function patchPayment(index, patch) {
    setForm((current) => ({ ...current, payment_splits: current.payment_splits.map((row, i) => i === index ? { ...row, ...patch } : row) }))
  }

  function selectSupplier(supplierId) {
    setForm((current) => ({ ...current, supplier_id: supplierId, lines: [{ ...emptyLine }] }))
  }

  function selectProduct(index, productId) {
    const product = (data.options?.products || []).find((row) => String(row.id) === String(productId))
    patchLine(index, {
      retail_product_id: productId,
      purchase_uom: product?.purchase_uom || product?.basic_uom || product?.unit || '',
      conversion_factor: String(product?.purchase_to_basic_factor ?? 1),
      purchase_unit_cost: product ? String(Number(product.purchase_uom_cost ?? (Number(product.unit_cost || 0) * Number(product.purchase_to_basic_factor || 1))).toFixed(2)) : '0',
      basic_uom: product?.basic_uom || product?.unit || '',
    })
  }

  async function apply(event) {
    event?.preventDefault(); setLoading(true); setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load stock receipts.')) } finally { setLoading(false) }
  }

  async function reset() {
    const next = { branch_id: filters.branch_id, supplier_id: '', product_id: '', from: monthStart(), to: today(), search: '' }
    setFilters(next); setLoading(true); setMessage('')
    try { await load(next) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load stock receipts.')) } finally { setLoading(false) }
  }

  async function submit(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const normalizedPayments = form.payment_status === 'PAID'
        ? (form.payment_splits || [])
          .filter((split) => split.funding_account_id || Number(split.amount || 0) > 0 || split.reference)
          .map((split) => ({
            funding_account_id: Number(split.funding_account_id),
            amount: Number(split.amount),
            reference: split.reference || null,
          }))
        : []

      if (form.payment_status === 'PAID' && !normalizedPayments.length) {
        setSaving(false)
        return setMessage('Add at least one Mode of Payment for a Paid stock receipt.')
      }
      if (normalizedPayments.some((split) => !split.funding_account_id || split.amount <= 0)) {
        setSaving(false)
        return setMessage('Select a Mode of Payment and enter an amount for every payment line.')
      }
      if (form.payment_status === 'PAID' && paymentTotal - grandTotal > 0.01) {
        setSaving(false)
        return setMessage('Payment total cannot exceed the Grand Total.')
      }
      if (form.payment_status === 'PAID' && Math.abs(paymentTotal - grandTotal) > 0.01) {
        setSaving(false)
        return setMessage('Paid stock receipts must be fully allocated before posting.')
      }

      const attachmentTokens = await uploadSupportingDocuments(form.supporting_files, 'stock-received', 'STOCK_RECEIVED_SUPPORT')
      const { supporting_files, ...formPayload } = form
      const response = await api.post('/v1/inventory/receipts', {
        ...formPayload,
        branch_id: Number(form.branch_id),
        supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
        delivery_fee: Number(form.delivery_fee || 0),
        payment_status: form.payment_status,
        payment_splits: normalizedPayments,
        attachment_tokens: attachmentTokens,
        lines: form.lines.map((line) => ({
          retail_product_id: Number(line.retail_product_id),
          purchase_quantity: Number(line.purchase_quantity),
          purchase_uom: line.purchase_uom,
          conversion_factor: Number(line.conversion_factor),
          purchase_unit_cost: Number(line.purchase_unit_cost),
          update_product_conversion: false,
        })),
      })
      setForm(null)
      setSelected(response.data.receipt)
      await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to receive stock.')) } finally { setSaving(false) }
  }

  async function requestCorrection(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(correction.supporting_files, 'stock-received', 'STOCK_RECEIVED_CORRECTION_SUPPORT')
      await api.post(`/v1/inventory/receipts/${correction.receipt_id}/lines/${correction.line_id}/correction-requests`, {
        corrected_purchase_quantity: Number(correction.corrected_purchase_quantity),
        corrected_purchase_unit_cost: Number(correction.corrected_purchase_unit_cost),
        reason_reference_id: Number(correction.reason_reference_id),
        reason: correction.reason,
        attachment_tokens: attachmentTokens,
      })
      const receiptId = correction.receipt_id
      setCorrection(null)
      const response = await api.get('/v1/inventory/receipts', { params: { ...filters, branch_id: filters.branch_id || undefined, supplier_id: filters.supplier_id || undefined, product_id: filters.product_id || undefined, search: filters.search || undefined } })
      setData(response.data); setSelected(response.data.receipts?.find((row) => Number(row.id) === Number(receiptId)) || null)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to submit item correction.')) } finally { setSaving(false) }
  }

  async function requestReversal(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(reversing.supporting_files, 'stock-received', 'STOCK_RECEIVED_REVERSAL_SUPPORT')
      await api.post(`/v1/inventory/receipts/${reversing.id}/reversal-requests`, { reason_reference_id: Number(reversing.reason_reference_id), reason: reversing.reason, attachment_tokens: attachmentTokens })
      const receiptId = reversing.id; setReversing(null)
      const response = await api.get('/v1/inventory/receipts', { params: { ...filters, branch_id: filters.branch_id || undefined, supplier_id: filters.supplier_id || undefined, product_id: filters.product_id || undefined, search: filters.search || undefined } })
      setData(response.data); setSelected(response.data.receipts?.find((row) => Number(row.id) === Number(receiptId)) || null)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to submit stock receipt reversal.')) } finally { setSaving(false) }
  }

  async function approveAction() {
    if (!actionApproval) return
    const suffix = actionApproval.action_type === 'REVERSAL' ? 'approve-reversal' : 'approve-correction'
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/inventory/receipt-action-requests/${actionApproval.id}/${suffix}`, {})
      const receiptId = actionApproval.receipt_id || selected?.id; setActionApproval(null)
      const response = await api.get('/v1/inventory/receipts', { params: { ...filters, branch_id: filters.branch_id || undefined, supplier_id: filters.supplier_id || undefined, product_id: filters.product_id || undefined, search: filters.search || undefined } })
      setData(response.data); setSelected(response.data.receipts?.find((row) => Number(row.id) === Number(receiptId)) || null)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to approve stock receipt request.')) } finally { setSaving(false) }
  }

  async function rejectAction(event) {
    event.preventDefault()
    const suffix = actionReview.action_type === 'REVERSAL' ? 'reject-reversal' : 'reject-correction'
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/inventory/receipt-action-requests/${actionReview.id}/${suffix}`, { review_reason_reference_id: Number(actionReview.review_reason_reference_id), notes: actionReview.notes })
      const receiptId = actionReview.receipt_id || selected?.id; setActionReview(null)
      const response = await api.get('/v1/inventory/receipts', { params: { ...filters, branch_id: filters.branch_id || undefined, supplier_id: filters.supplier_id || undefined, product_id: filters.product_id || undefined, search: filters.search || undefined } })
      setData(response.data); setSelected(response.data.receipts?.find((row) => Number(row.id) === Number(receiptId)) || null)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to reject stock receipt request.')) } finally { setSaving(false) }
  }

  async function receiveCorrectionRefund(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(correctionRefund.supporting_files, 'stock-received', 'SUPPLIER_REFUND_PROOF')
      await api.post(`/v1/inventory/receipt-action-requests/${correctionRefund.id}/refunds`, {
        refund_date: correctionRefund.refund_date,
        receiving_account_id: Number(correctionRefund.receiving_account_id),
        amount: Number(correctionRefund.amount),
        reference: correctionRefund.reference || null,
        attachment_tokens: attachmentTokens,
      })
      const receiptId = selected?.id
      setCorrectionRefund(null)
      const response = await api.get('/v1/inventory/receipts', { params: { ...filters, branch_id: filters.branch_id || undefined, supplier_id: filters.supplier_id || undefined, product_id: filters.product_id || undefined, search: filters.search || undefined } })
      setData(response.data); setSelected(response.data.receipts?.find((row) => Number(row.id) === Number(receiptId)) || null)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to record supplier refund.')) } finally { setSaving(false) }
  }

  return <div className="page-stack">
    <section className="page-heading live-page-heading"><div><h1 className="live-page-title">Stock Received <PageRefreshButton label="Refresh stock received" disabled={loading || saving} onRefresh={() => load(filters)} /></h1><p>Receive purchases in Purchase UOM and automatically convert them into the item's Basic UOM.</p></div>{hasPermission('stock-received', 'CREATE') ? <button className="primary-button" type="button" onClick={openReceive}>Receive Stock</button> : null}</section>
    {message ? <div className="form-alert error">{message}</div> : null}
    <section className="content-card"><form className="inventory-control-filters stock-received-filter-grid" onSubmit={apply}>
      <label className="form-field stock-received-branch-filter"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field stock-received-supplier-filter"><span>Supplier Name</span><SearchableSelect value={filters.supplier_id} onChange={(e) => setFilters((c) => ({ ...c, supplier_id: e.target.value }))}><option value="">All Suppliers</option>{(data.options?.suppliers || []).map((row) => <option key={row.id} value={row.id}>{row.code_name || row.company_name}</option>)}</SearchableSelect></label>
      <label className="form-field stock-received-item-filter"><span>Item Name</span><SearchableSelect value={filters.product_id} onChange={(e) => setFilters((c) => ({ ...c, product_id: e.target.value }))}><option value="">All Items</option>{(data.options?.products || []).map((row) => <option key={row.id} value={row.id}>{row.name || row.label}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
      <label className="form-field inventory-control-search"><span>Search</span><input value={filters.search} onChange={(e) => setFilters((c) => ({ ...c, search: e.target.value }))} placeholder="Receipt or reference" /></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
    </form></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table stock-receipts-table"><thead><tr><th>Receipt</th><th>Branch</th><th>Supplier</th><th>Reference</th><th>Items</th><th>Basic Qty</th><th>Total Amount</th><th>Delivery Fee</th><th>Payment</th><th>Received By</th><th>Status</th><th>Action</th></tr></thead><tbody>{(data.receipts || []).map((row) => {
      const paid = (row.payment_splits || []).reduce((sum, split) => sum + Number(split.amount || 0), 0)
      const due = Math.max(0, Number((row.grand_total ?? row.total_cost) || 0) - paid)
      return <tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{displayDate(row.received_date)}</span></td><td>{row.branch?.name || '—'}</td><td>{row.supplier_code_name || row.supplier_name || '—'}</td><td>{row.reference_no || '—'}</td><td>{row.lines?.length || 0}</td><td>{(row.lines || []).reduce((sum, line) => sum + Number(line.quantity_received || 0), 0)}</td><td>{formatPeso(row.grand_total ?? row.total_cost)}</td><td>{formatPeso(row.delivery_fee || 0)}</td><td><strong>{row.payment_status || 'UNPAID'}</strong><span className="table-subline">{(row.payment_splits || []).map(paymentLabel).filter((value) => value !== '—').join(' + ') || (due > 0.009 ? 'Balance in AP' : '—')}</span></td><td>{row.created_by || '—'}</td><td><span className={`status-pill ${row.status==='REVERSED'?'danger':'success'}`}>{row.status}</span>{row.pending_action?<span className="table-subline">Pending {row.pending_action.action_type.toLowerCase()}</span>:null}</td><td className="action-cell"><ActionMenu items={[{label:'View',onClick:()=>setSelected(row)},canRequestReversal&&row.status==='POSTED'&&!row.pending_action?{label:'Request Reversal',onClick:()=>setReversing({...row,reason_reference_id:'',reason:'',supporting_files:[]})}:null]}/></td></tr>
    })}{!loading && !(data.receipts || []).length ? <tr><td colSpan="12"><div className="empty-state">No stock receipts found.</div></td></tr> : null}</tbody></table></div></section>

    {form ? <Modal title="Receive Stock" subtitle="Purchase quantity is converted to Basic UOM before stock is posted." onClose={() => setForm(null)} wide className="inventory-receive-modal">
      <form className="inventory-receive-form" onSubmit={submit}>
        <div className="inventory-receive-header-grid">
          <label className="form-field"><span>Branch</span><SearchableSelect required value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })}>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Date Received</span><input required type="date" max={today()} value={form.received_date} onChange={(e) => setForm({ ...form, received_date: e.target.value })} /></label>
          <label className="form-field"><span>Supplier</span><SearchableSelect required value={form.supplier_id} onChange={(e) => selectSupplier(e.target.value)}><option value="">Select supplier</option>{(data.options?.suppliers || []).map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.code_name || supplier.company_name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Reference</span><input value={form.reference_no} onChange={(e) => setForm({ ...form, reference_no: e.target.value })} placeholder="Optional reference" /></label>
          <label className="form-field"><span>Payment Status</span><SearchableSelect value={form.payment_status} onChange={(e) => { const next = e.target.value; setForm((current) => ({ ...current, payment_status: next, payment_splits: next === 'PAID' ? (current.payment_splits?.length ? current.payment_splits : [{ ...emptyPayment }]) : [{ ...emptyPayment }] })) }}><option value="UNPAID">Unpaid</option><option value="PAID">Paid</option></SearchableSelect></label>
        </div>

        <div className="inventory-line-section">
          <div className="inventory-receipt-lines inventory-receipt-lines-table">{form.lines.map((line, index) => <div className="inventory-receipt-line inventory-receipt-line-single" key={index}>
            <label className="form-field inventory-receipt-item"><span>Item</span><SearchableSelect required value={line.retail_product_id} onChange={(e) => selectProduct(index, e.target.value)}><option value="">Select item</option>{supplierProducts.map((product) => <option key={product.id} value={product.id}>{product.sku} · {product.name}</option>)}</SearchableSelect></label>
            <label className="form-field"><span>Purchase Qty</span><input required type="number" min="0.0001" step="0.0001" value={line.purchase_quantity} onChange={(e) => patchLine(index, { purchase_quantity: e.target.value })} /></label>
            <label className="form-field"><span>Purchase UOM</span><SearchableSelect required value={line.purchase_uom} disabled>{(data.options?.uoms || []).map((uom) => <option key={uom.code} value={uom.value}>{uom.value}</option>)}</SearchableSelect></label>
            <label className="form-field"><span>Conversion</span><input required type="number" min="0.000001" step="0.000001" value={line.conversion_factor} disabled /></label>
            <label className="form-field"><span>Unit Cost</span><input required type="number" min="0" step="0.01" value={line.purchase_unit_cost} onChange={(e) => patchLine(index, { purchase_unit_cost: e.target.value })} /></label>
            <label className="form-field inventory-readonly-field"><span>Basic Qty</span><div className="inventory-readonly-value">{convertedBasicQty(line).toLocaleString('en-PH')} {line.basic_uom || ''}</div></label>
            <label className="form-field inventory-readonly-field"><span>Total Cost</span><div className="inventory-readonly-value">{formatPeso(lineTotal(line))}</div></label>
            <button className="secondary-button compact-button inventory-line-remove" type="button" disabled={form.lines.length === 1} onClick={() => setForm((current) => ({ ...current, lines: current.lines.filter((_, i) => i !== index) }))}>Remove</button>
          </div>)}</div>
          <button className="secondary-button compact-button inventory-add-line-button" type="button" onClick={() => setForm((current) => ({ ...current, lines: [...current.lines, { ...emptyLine }] }))}>Add Item</button>
        </div>

        <div className="inventory-receive-settlement">
          <div className="inventory-receive-totals">
            <div><span>Total (Items)</span><strong>{formatPeso(formTotal)}</strong></div>
            <label><span>Delivery Fee</span><input type="number" min="0" step="0.01" value={form.delivery_fee} onChange={(e) => setForm({ ...form, delivery_fee: e.target.value })} /></label>
            <div className="inventory-receive-grand-total"><span>Grand Total</span><strong>{formatPeso(grandTotal)}</strong></div>
          </div>

          {form.payment_status === 'PAID' ? <div className="inventory-payment-splits inventory-receive-payment-lines inventory-payment-card-sleek">
            <div className="inventory-subsection-heading inventory-payment-heading-sleek"><div><strong>Payment Allocation</strong><span>Allocate the Grand Total across one or more funding sources.</span></div><button className="secondary-button compact-button" type="button" onClick={() => setForm((current) => ({ ...current, payment_splits: [...current.payment_splits, { ...emptyPayment }] }))}>+ Split Payment</button></div>
            <div className="inventory-payment-column-headings"><span>Mode of Payment</span><span>Amount</span><span>Reference</span><span></span></div>
            {(form.payment_splits || []).map((split, index) => <div className="inventory-payment-split-row inventory-payment-split-row-sleek" key={index}>
              <SearchableSelect aria-label={`Mode of Payment ${index + 1}`} required value={split.funding_account_id} onChange={(e) => patchPayment(index,{funding_account_id:e.target.value})}><option value="">Select mode</option>{(data.options?.funding_sources || []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}</SearchableSelect>
              <input aria-label={`Payment Amount ${index + 1}`} required type="number" min="0.01" step="0.01" value={split.amount} onChange={(e) => patchPayment(index,{amount:e.target.value})} placeholder="0.00" />
              <input aria-label={`Payment Reference ${index + 1}`} value={split.reference} onChange={(e) => patchPayment(index,{reference:e.target.value})} placeholder="Optional" />
              <button className="secondary-button compact-button" type="button" disabled={form.payment_splits.length===1} onClick={() => setForm((current)=>({...current,payment_splits:current.payment_splits.filter((_,i)=>i!==index)}))}>Remove</button>
            </div>)}
            <div className="inventory-receive-balance inventory-receive-balance-sleek"><span>Remaining</span><strong>{formatPeso(balance)}</strong><small>{balance > 0.009 ? 'Complete the allocation to post as Paid.' : 'Fully allocated'}</small></div>
          </div> : <div className="inventory-unpaid-ap-summary"><div><span>Accounts Payable</span><small>Unpaid receipt. The full Grand Total will be posted to AP.</small></div><strong>{formatPeso(grandTotal)}</strong></div>}

        </div>

        <label className="form-field"><span>Notes</span><textarea rows="3" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label>
        <SupportingDocumentField required files={form.supporting_files} onChange={(files) => setForm({ ...form, supporting_files: files })} label="Receiving Document" hint="Required before posting. Attach the supplier invoice, delivery receipt or receiving document." />
        <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Posting...' : 'Post Stock Receipt'}</button></div>
      </form>
    </Modal> : null}

    {selected ? <Modal title={`Stock Receipt · ${selected.code}`} subtitle={`${displayDate(selected.received_date)} · ${selected.branch?.name || ''}`} onClose={() => setSelected(null)} wide>
      {(() => {
        const paid = (selected.payment_splits || []).reduce((sum, split) => sum + Number(split.amount || 0), 0)
        const due = Math.max(0, Number((selected.grand_total ?? selected.total_cost) || 0) - paid)
        return <div className="inventory-detail-grid inventory-detail-grid-payment"><div><span>Supplier</span><strong>{selected.supplier_code_name || selected.supplier_name || '—'}</strong></div><div><span>Reference</span><strong>{selected.reference_no || '—'}</strong></div><div><span>Items Total</span><strong>{formatPeso(selected.total_cost)}</strong></div><div><span>Delivery Fee</span><strong>{formatPeso(selected.delivery_fee || 0)}</strong></div><div><span>Grand Total</span><strong>{formatPeso(selected.grand_total ?? selected.total_cost)}</strong></div><div><span>Payment Status</span><strong>{selected.payment_status || 'UNPAID'}</strong></div><div><span>Funding / Payment</span><strong>{(selected.payment_splits || []).map((split)=>`${paymentLabel(split)} ${formatPeso(split.amount)}`).join(' + ') || '—'}</strong></div><div><span>Balance in AP</span><strong>{formatPeso(due)}</strong></div></div>
      })()}
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Purchase Qty</th><th>Conversion</th><th>Basic Qty</th><th>Purchase Unit Cost</th><th>Basic Unit Cost</th><th>Total Cost</th><th className="actions-column">Action</th></tr></thead><tbody>{(selected.lines || []).map((line) => <tr key={line.id}><td><strong>{line.name}</strong>{line.has_correction?<span className="table-subline">Corrected</span>:null}</td><td>{line.purchase_quantity} {line.purchase_uom}</td><td>× {line.conversion_factor}</td><td>{line.quantity_received} {line.basic_uom || line.unit}</td><td>{formatPeso(line.purchase_unit_cost)}</td><td>{formatPeso(line.unit_cost)}</td><td>{formatPeso(line.line_total)}</td><td className="action-cell"><ActionMenu items={[canRequestCorrection&&selected.status==='POSTED'&&!selected.pending_action?{label:'Request Item Correction',onClick:()=>setCorrection({receipt_id:selected.id,line_id:line.id,name:line.name,purchase_uom:line.purchase_uom,corrected_purchase_quantity:String(line.purchase_quantity),corrected_purchase_unit_cost:String(line.purchase_unit_cost),reason_reference_id:'',reason:'',supporting_files:[]})}:null]}/></td></tr>)}</tbody></table></div>
      {selected.pending_action ? <div className="inventory-detail-notes"><span>Pending {selected.pending_action.action_type === 'REVERSAL' ? 'Receipt Reversal' : 'Item Correction'}</span><p>{selected.pending_action.reason}</p><small>Requested by {selected.pending_action.requested_by || '—'}</small><div className="modal-actions">{((selected.pending_action.action_type==='REVERSAL'&&canApproveReversal)||(selected.pending_action.action_type==='CORRECTION'&&canApproveCorrection))?<><button className="secondary-button" type="button" onClick={()=>setActionReview({...selected.pending_action,receipt_id:selected.id,review_reason_reference_id:'',notes:''})}>Reject</button><button className="primary-button" type="button" onClick={()=>setActionApproval({...selected.pending_action,receipt_id:selected.id})}>Approve</button></>:null}</div></div> : null}
      {(selected.action_requests || []).some((action)=>action.status!=='PENDING') ? <div className="inventory-detail-notes"><span>Correction / Reversal History</span><div className="table-wrap"><table className="data-table"><thead><tr><th>Request</th><th>Type</th><th>Status</th><th>Refund Due</th><th>Reviewed By</th><th>Action</th></tr></thead><tbody>{(selected.action_requests || []).filter((action)=>action.status!=='PENDING').map((action)=><tr key={action.id}><td><strong>{action.code}</strong><span className="table-subline">{action.reason}</span></td><td>{action.action_type}</td><td><span className={`status-pill ${action.status==='APPROVED'?'success':'danger'}`}>{action.status}</span></td><td>{action.action_type==='CORRECTION' ? formatPeso(action.refund_remaining || 0) : '—'}</td><td>{action.reviewed_by || '—'}</td><td className="action-cell"><ActionMenu items={[canReceiveCorrectionRefund&&action.action_type==='CORRECTION'&&action.status==='APPROVED'&&Number(action.refund_remaining||0)>0.004?{label:'Receive Supplier Refund',onClick:()=>setCorrectionRefund({id:action.id,code:action.code,refund_date:today(),receiving_account_id:'',amount:String(action.refund_remaining),reference:'',supporting_files:[]})}:null]}/></td></tr>)}</tbody></table></div></div> : null}
      {selected.notes ? <div className="inventory-detail-notes"><span>Notes</span><p>{selected.notes}</p></div> : null}
      <SupportingDocumentList entityType="INVENTORY_RECEIPT" entityId={selected.id} />
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setSelected(null)}>Close</button></div>
    </Modal> : null}

    {correction ? <Modal title={`Correct Item · ${correction.name}`} subtitle="The original receipt remains intact. The correction affects inventory and accounting only after approval." onClose={()=>setCorrection(null)}><form className="form-grid" onSubmit={requestCorrection}><label className="form-field"><span>Correct Purchase Qty</span><input required type="number" min="0" step="0.0001" value={correction.corrected_purchase_quantity} onChange={e=>setCorrection({...correction,corrected_purchase_quantity:e.target.value})}/></label><label className="form-field"><span>Correct Unit Cost</span><input required type="number" min="0" step="0.01" value={correction.corrected_purchase_unit_cost} onChange={e=>setCorrection({...correction,corrected_purchase_unit_cost:e.target.value})}/></label><WorkflowReasonSelect context="STOCK_RECEIPT_CORRECTION" className="form-span-2" value={correction.reason_reference_id} onChange={e=>setCorrection({...correction,reason_reference_id:e.target.value})}/><label className="form-field form-span-2"><span>Details</span><textarea required minLength="5" rows="4" value={correction.reason} onChange={e=>setCorrection({...correction,reason:e.target.value})}/></label><div className="form-span-2"><SupportingDocumentField files={correction.supporting_files||[]} onChange={(files)=>setCorrection({...correction,supporting_files:files})} label="Supporting Document" hint="Optional. Attach supplier or receiving evidence supporting this correction." /></div><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={()=>setCorrection(null)}>Cancel</button><button className="primary-button" disabled={saving}>Submit for Approval</button></div></form></Modal> : null}

    {reversing ? <Modal title={`Reverse Stock Receipt · ${reversing.code}`} subtitle="Use this only for an erroneous receipt. Real supplier returns belong in Stock Returns." onClose={()=>setReversing(null)}><form onSubmit={requestReversal}><WorkflowReasonSelect context="STOCK_RECEIPT_REVERSAL" value={reversing.reason_reference_id} onChange={e=>setReversing({...reversing,reason_reference_id:e.target.value})}/><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversing.reason} onChange={e=>setReversing({...reversing,reason:e.target.value})}/></label><SupportingDocumentField files={reversing.supporting_files||[]} onChange={(files)=>setReversing({...reversing,supporting_files:files})} label="Supporting Document" hint="Optional. Attach supplier, receiving or correction evidence." /><div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setReversing(null)}>Cancel</button><button className="danger-button" disabled={saving}>Submit for Approval</button></div></form></Modal> : null}

    {correctionRefund ? <Modal title={`Receive Supplier Refund · ${correctionRefund.code}`} subtitle="Clears the Supplier Refunds Receivable created by an approved stock receipt correction." onClose={()=>setCorrectionRefund(null)}><form className="form-grid" onSubmit={receiveCorrectionRefund}><label className="form-field"><span>Refund Date</span><input required type="date" max={today()} value={correctionRefund.refund_date} onChange={e=>setCorrectionRefund({...correctionRefund,refund_date:e.target.value})}/></label><label className="form-field"><span>Receiving Bank / E-Wallet</span><SearchableSelect required value={correctionRefund.receiving_account_id} onChange={e=>setCorrectionRefund({...correctionRefund,receiving_account_id:e.target.value})}><option value="">Select receiving account</option>{(data.options?.receiving_accounts || []).map((account)=><option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={correctionRefund.amount} onChange={e=>setCorrectionRefund({...correctionRefund,amount:e.target.value})}/></label><label className="form-field"><span>Reference No.</span><input value={correctionRefund.reference} onChange={e=>setCorrectionRefund({...correctionRefund,reference:e.target.value})} placeholder="Optional"/></label><div className="form-span-2"><SupportingDocumentField required files={correctionRefund.supporting_files||[]} onChange={(files)=>setCorrectionRefund({...correctionRefund,supporting_files:files})} label="Refund Proof" hint="Required. Attach the bank/e-wallet credit, supplier refund confirmation or equivalent proof." /></div><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={()=>setCorrectionRefund(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving?'Posting...':'Receive Refund'}</button></div></form></Modal> : null}

    {actionApproval ? <ActionConfirmationModal title={actionApproval.action_type==='REVERSAL'?'Approve Receipt Reversal':'Approve Item Correction'} subtitle={actionApproval.code} message={actionApproval.action_type==='REVERSAL'?'Approval will reverse the stock receipt, inventory quantity, and its net accounting effect.':'Approval will post the item correction to inventory and accounting while preserving the original receipt history.'} confirmLabel="Approve & Post" busy={saving} onCancel={()=>setActionApproval(null)} onConfirm={approveAction}/> : null}

    {actionReview ? <Modal title={`Reject ${actionReview.code}`} onClose={()=>setActionReview(null)}><form onSubmit={rejectAction}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={actionReview.review_reason_reference_id} onChange={e=>setActionReview({...actionReview,review_reason_reference_id:e.target.value})}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={actionReview.notes} onChange={e=>setActionReview({...actionReview,notes:e.target.value})}/></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setActionReview(null)}>Cancel</button><button className="danger-button" disabled={saving}>Reject Request</button></div></form></Modal> : null}
  </div>
}
