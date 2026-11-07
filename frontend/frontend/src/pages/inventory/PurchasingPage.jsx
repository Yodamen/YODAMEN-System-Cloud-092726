import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import Modal from '../../components/common/Modal'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import ActionMenu from '../../components/common/ActionMenu'
import SearchableSelect from '../../components/common/SearchableSelect'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './purchasing.css'

const currentDate = () => {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const part = (type) => parts.find((item) => item.type === type)?.value
  return `${part('year')}-${part('month')}-${part('day')}`
}
const newLine = () => ({ retail_product_id: '', purchase_quantity: '1', purchase_uom: '', conversion_factor: '1', purchase_unit_cost: '0' })
const money = (v) => `₱${Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export default function PurchasingPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ orders: [], options: { suppliers: [], branches: [], products: [] } })
  const [status, setStatus] = useState('')
  const [form, setForm] = useState(null)
  const [receiving, setReceiving] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [selected, setSelected] = useState(null)
  const [confirmation, setConfirmation] = useState(null)
  const [cancelReason, setCancelReason] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    const { data: response } = await api.get('/v1/purchasing/orders')
    setData(response)
    setSelected((prev) => prev ? response.orders.find((order) => order.id === prev.id) || null : null)
  }, [])
  useEffect(() => { load().catch((e) => setMessage(getApiErrorMessage(e))).finally(() => setLoading(false)) }, [load])
  const orders = useMemo(() => status ? data.orders.filter((order) => order.status === status) : data.orders, [data.orders, status])
  const supplier = data.options.suppliers.find((row) => String(row.id) === String(form?.supplier_id))
  const products = (data.options.products || []).filter((item) => !supplier || supplier.item_ids?.map(String).includes(String(item.id)))
  const subtotal = (form?.lines || []).reduce((total, item) => total + Number(item.purchase_quantity || 0) * Number(item.purchase_unit_cost || 0), 0)

  function startCreate(order = null) {
    setMessage('')
    setForm(order ? {
      ...order, branch_id: String(order.branch_id), supplier_id: String(order.supplier_id),
      lines: order.lines.map((line) => ({ retail_product_id: String(line.retail_product_id), purchase_quantity: String(line.purchase_quantity), purchase_uom: line.purchase_uom, conversion_factor: String(line.conversion_factor), purchase_unit_cost: String(line.purchase_unit_cost) })),
    } : { branch_id: String(data.options.branches?.[0]?.id || ''), supplier_id: '', order_date: currentDate(), expected_delivery_date: '', reference_no: '', notes: '', lines: [newLine()] })
  }
  function changeLine(index, field, value) {
    setForm((prev) => ({ ...prev, lines: prev.lines.map((line, at) => {
      if (index !== at) return line
      if (field !== 'retail_product_id') return { ...line, [field]: value }
      const product = data.options.products.find((item) => String(item.id) === String(value))
      return { ...line, retail_product_id: value, purchase_uom: product?.purchase_uom || product?.unit || '', conversion_factor: String(product?.purchase_to_basic_factor || 1), purchase_unit_cost: String(product?.purchase_uom_cost ?? product?.unit_cost ?? 0) }
    }) }))
  }
  async function save(event) {
    event.preventDefault(); setBusy(true); setMessage('')
    try {
      const payload = { ...form, expected_delivery_date: form.expected_delivery_date || null }
      if (form.id) await api.put(`/v1/purchasing/orders/${form.id}`, payload)
      else await api.post('/v1/purchasing/orders', payload)
      setForm(null); await load()
      setMessage('Purchase Order draft saved.')
    } catch (e) { setMessage(getApiErrorMessage(e)) } finally { setBusy(false) }
  }
  function transition(order, action) {
    setCancelReason('')
    setConfirmation({ order, action })
  }
  async function executeTransition() {
    if (!confirmation) return
    const { order, action } = confirmation
    if (action === 'cancel' && cancelReason.trim().length < 5) {
      setMessage('Enter a cancellation reason of at least five characters.')
      return
    }
    setBusy(true); setMessage('')
    try {
      await api.post(`/v1/purchasing/orders/${order.id}/${action}`, action === 'cancel' ? { reason: cancelReason.trim() } : {})
      setConfirmation(null); setCancelReason('')
      await load(); setMessage(`Purchase Order ${action === 'submit' ? 'submitted' : action === 'approve' ? 'approved' : 'cancelled'} successfully.`)
    } catch (e) { setMessage(getApiErrorMessage(e)) } finally { setBusy(false) }
  }
  function startReceive(order) {
    setReceiving({ order, received_date: currentDate(), reference_no: '', notes: '', files: [], lines: order.lines.filter((line) => line.remaining_quantity > 0).map((line) => ({ purchase_order_line_id: line.id, purchase_quantity: String(line.remaining_quantity), product_name: line.product_name, remaining: line.remaining_quantity, uom: line.purchase_uom })) })
    setMessage('')
  }
  function postReceive(event) {
    event.preventDefault()
    if (!receiving?.files?.length || !receiving.lines.some(line => Number(line.purchase_quantity) > 0)) {
      setMessage('Add receiving quantities and the supplier receiving document.')
      return
    }
    setConfirmation({ action: 'receive', order: receiving.order })
  }
  async function executeReceive() {
    if (!receiving) return
    setBusy(true); setMessage('')
    try {
      const lines = receiving.lines.filter((line) => Number(line.purchase_quantity) > 0).map(({ purchase_order_line_id, purchase_quantity }) => ({ purchase_order_line_id, purchase_quantity }))
      if (!lines.length || !receiving.files.length) throw new Error('Add receiving quantities and the supplier receiving document.')
      const tokens = await uploadSupportingDocuments(receiving.files, 'stock-received', 'STOCK_RECEIVED_SUPPORT')
      await api.post(`/v1/purchasing/orders/${receiving.order.id}/receive`, { received_date: receiving.received_date, reference_no: receiving.reference_no, notes: receiving.notes, attachment_tokens: tokens, lines })
      setReceiving(null); setConfirmation(null); await load(); setMessage('Stock receipt posted. Inventory and Accounts Payable updated by the existing receiving service.')
    } catch (e) { setMessage(getApiErrorMessage(e, e.message || 'Unable to post receipt.')) } finally { setBusy(false) }
  }
  const formatDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—'
  const receptionLabel = (order) => [...new Set((order.receipts || []).filter((receipt) => receipt.status === 'POSTED').map((receipt) => receipt.received_by).filter(Boolean))].join(', ') || '—'
  const statusStyle = (value) => value === 'APPROVED' || value === 'FULLY_RECEIVED' ? 'success' : value === 'CANCELLED' || value === 'REVERSED' ? 'danger' : value === 'SUBMITTED' || value === 'PARTIALLY_RECEIVED' ? 'info' : 'neutral'
  return <div className="page-stack purchasing-page">
    <section className="page-heading live-page-heading">
      <div><h1 className="live-page-title">Purchasing <PageRefreshButton label="Refresh purchase orders" disabled={busy || loading} onRefresh={() => load().catch((e) => setMessage(getApiErrorMessage(e)))} /></h1><p>Manage Purchase Orders, approval and supplier delivery receiving.</p></div>
      {hasPermission('purchasing', 'CREATE') ? <button className="primary-button" type="button" onClick={() => startCreate()}>New Purchase Order</button> : null}
    </section>
    {message ? <div role="status" className="form-alert">{message}</div> : null}
    <section className="content-card"><div className="purchase-filters">
      <label className="form-field"><span>Status</span><SearchableSelect value={status} onChange={(e) => setStatus(e.target.value)}><option value="">All Statuses</option>{['DRAFT', 'SUBMITTED', 'APPROVED', 'REVERSED', 'CANCELLED'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</SearchableSelect></label>
      <div className="filter-actions-inline"><button type="button" className="secondary-button" onClick={() => load().catch((e) => setMessage(getApiErrorMessage(e)))}>Refresh</button></div>
    </div></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table purchase-orders-table"><thead><tr><th>PO No.</th><th>Supplier</th><th>Branch</th><th>Order Date</th><th>Expected Delivery</th><th>Total Amount</th><th>Status</th><th>Receiving Status</th><th>Received By</th><th>Action</th></tr></thead><tbody>
      {orders.map((order) => <tr key={order.id}>
        <td><button type="button" className="purchase-text-link" onClick={() => setSelected(order)}>{order.code}</button></td>
        <td>{order.supplier_name || '—'}</td><td>{order.branch_name || '—'}</td><td>{formatDate(order.order_date)}</td><td>{formatDate(order.expected_delivery_date)}</td><td>{money(order.total_cost)}</td>
        <td><span className={`status-pill ${statusStyle(order.status)}`}>{order.status}</span></td>
        <td><span className={`status-pill ${statusStyle(order.receiving_status)}`}>{(order.receiving_status || 'NOT_RECEIVED').replaceAll('_', ' ')}</span></td>
        <td>{receptionLabel(order)}</td>
        <td className="action-cell"><ActionMenu items={[
          {label:'View Details',onClick:()=>setSelected(order)},
          order.status==='DRAFT'&&hasPermission('purchasing','EDIT')?{label:'Edit Draft',onClick:()=>startCreate(order)}:null,
          order.status==='DRAFT'&&hasPermission('purchasing','SUBMIT')?{label:'Submit for Approval',disabled:busy,onClick:()=>transition(order,'submit')}:null,
          order.status==='SUBMITTED'&&hasPermission('purchasing','APPROVE')?{label:'Approve PO',disabled:busy,onClick:()=>transition(order,'approve')}:null,
          order.status==='APPROVED'&&order.receiving_status!=='FULLY_RECEIVED'&&hasPermission('purchasing','RECEIVE')&&hasPermission('stock-received','CREATE')?{label:'Receive Delivery',onClick:()=>startReceive(order)}:null,
          ['DRAFT','SUBMITTED','APPROVED'].includes(order.status)&&!order.receipts.some(r=>r.status==='POSTED')&&hasPermission('purchasing','CANCEL')?{label:'Cancel PO',disabled:busy,onClick:()=>transition(order,'cancel')}:null,
        ]}/></td>
      </tr>)}
      {!orders.length ? <tr><td colSpan="10"><div className="empty-state">{loading?'Loading Purchase Orders...':'No Purchase Orders found.'}</div></td></tr> : null}
    </tbody></table></div></section>
    {selected ? <Modal title={`Purchase Order · ${selected.code}`} subtitle={`${selected.supplier_name || ''} · ${selected.status}`} onClose={()=>setSelected(null)} wide>
      <div className="purchase-detail-summary"><div><span>Branch</span><strong>{selected.branch_name || '—'}</strong></div><div><span>Order Date</span><strong>{formatDate(selected.order_date)}</strong></div><div><span>Expected Delivery</span><strong>{formatDate(selected.expected_delivery_date)}</strong></div><div><span>Total</span><strong>{money(selected.total_cost)}</strong></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Purchase UOM</th><th>Unit Cost</th></tr></thead><tbody>{selected.lines.map((line)=><tr key={line.id}><td><strong>{line.product_name}</strong></td><td>{line.purchase_quantity}</td><td>{line.received_quantity}</td><td>{line.remaining_quantity}</td><td>{line.purchase_uom}</td><td>{money(line.purchase_unit_cost)}</td></tr>)}</tbody></table></div>
      <h3 className="purchase-section-title">Delivery History</h3>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Receipt</th><th>Date</th><th>Received By</th><th>Status</th></tr></thead><tbody>{selected.receipts.length?selected.receipts.map((receipt)=><tr key={receipt.id}><td>{receipt.code}</td><td>{formatDate(receipt.received_date)}</td><td>{receipt.received_by || '—'}</td><td><span className={`status-pill ${receipt.status==='POSTED'?'success':'danger'}`}>{receipt.status}</span></td></tr>):<tr><td colSpan="4">No deliveries received.</td></tr>}</tbody></table></div>
      <div className="modal-actions"><button type="button" className="secondary-button" onClick={()=>setSelected(null)}>Close</button></div>
    </Modal> : null}
    {form ? <Modal title={form.id?'Edit Purchase Order':'New Purchase Order'} subtitle="Draft order. Inventory and AP will be posted only when the delivery is received." onClose={()=>!busy&&setForm(null)} wide>
      <form className="purchase-form" onSubmit={save}>
        <div className="purchase-form-fields">
          <label className="form-field"><span>Branch</span><SearchableSelect required value={form.branch_id} onChange={e=>setForm({...form,branch_id:e.target.value})}><option value="">Select branch</option>{data.options.branches.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Supplier</span><SearchableSelect required value={form.supplier_id} onChange={e=>setForm({...form,supplier_id:e.target.value,lines:[newLine()]})}><option value="">Select supplier</option>{data.options.suppliers.map(x=><option key={x.id} value={x.id}>{x.code_name||x.company_name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Order Date</span><input required type="date" value={form.order_date} onChange={e=>setForm({...form,order_date:e.target.value})}/></label>
          <label className="form-field"><span>Expected Delivery</span><input type="date" min={form.order_date} value={form.expected_delivery_date||''} onChange={e=>setForm({...form,expected_delivery_date:e.target.value})}/></label>
          <label className="form-field"><span>Reference</span><input value={form.reference_no||''} onChange={e=>setForm({...form,reference_no:e.target.value})}/></label>
        </div>
        <h3 className="purchase-section-title">Order Items</h3>
        <div className="table-wrap"><table className="data-table purchase-entry-table"><thead><tr><th>Product</th><th>Purchase Qty</th><th>UOM</th><th>Conversion</th><th>Unit Cost</th><th>Total</th><th>Action</th></tr></thead><tbody>{form.lines.map((line,index)=><tr key={index}>
          <td><SearchableSelect required value={line.retail_product_id} onChange={e=>changeLine(index,'retail_product_id',e.target.value)}><option value="">Select item</option>{products.map(product=><option key={product.id} value={product.id}>{product.name}</option>)}</SearchableSelect></td>
          {['purchase_quantity','purchase_uom','conversion_factor','purchase_unit_cost'].map(field=><td key={field}><input required type={field==='purchase_uom'?'text':'number'} min={field==='purchase_unit_cost'?0:field==='purchase_uom'?undefined:0.000001} step={field==='purchase_quantity'?'0.0001':field==='conversion_factor'?'0.000001':'0.01'} value={line[field]} onChange={e=>changeLine(index,field,e.target.value)}/></td>)}
          <td>{money(Number(line.purchase_quantity)*Number(line.purchase_unit_cost))}</td><td><button type="button" className="secondary-button" disabled={form.lines.length===1} onClick={()=>setForm({...form,lines:form.lines.filter((_,at)=>at!==index)})}>Remove</button></td>
        </tr>)}</tbody></table></div>
        <button type="button" className="secondary-button" onClick={()=>setForm({...form,lines:[...form.lines,newLine()]})}>Add Item</button>
        <div className="purchase-total"><span>Order Total</span><strong>{money(subtotal)}</strong></div>
        <label className="form-field"><span>Notes</span><textarea rows="2" value={form.notes||''} onChange={e=>setForm({...form,notes:e.target.value})}/></label>
        <div className="modal-actions"><button type="button" className="secondary-button" disabled={busy} onClick={()=>setForm(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy?'Saving...':'Save Draft'}</button></div>
      </form>
    </Modal> : null}
    {receiving && confirmation?.action!=='receive' ? <Modal title={`Receive Delivery · ${receiving.order.code}`} subtitle="Posting will update Inventory and Accounts Payable through Stock Received." onClose={()=>!busy&&setReceiving(null)} wide>
      <form className="purchase-form" onSubmit={postReceive}>
        <div className="purchase-form-fields"><label className="form-field"><span>Received Date</span><input required type="date" min={receiving.order.order_date} max={currentDate()} value={receiving.received_date} onChange={e=>setReceiving({...receiving,received_date:e.target.value})}/></label><label className="form-field"><span>Supplier Delivery Reference</span><input value={receiving.reference_no} onChange={e=>setReceiving({...receiving,reference_no:e.target.value})}/></label></div>
        <h3 className="purchase-section-title">Quantities Received</h3>
        <div className="table-wrap"><table className="data-table purchase-receive-table"><thead><tr><th>Item</th><th>Remaining</th><th>Receive Now</th><th>UOM</th></tr></thead><tbody>{receiving.lines.map((line,index)=><tr key={line.purchase_order_line_id}><td>{line.product_name}</td><td>{line.remaining}</td><td><input type="number" min="0" max={line.remaining} step="0.0001" value={line.purchase_quantity} onChange={e=>setReceiving({...receiving,lines:receiving.lines.map((x,at)=>index===at?{...x,purchase_quantity:e.target.value}:x)})}/></td><td>{line.uom}</td></tr>)}</tbody></table></div>
        <SupportingDocumentField files={receiving.files} required onChange={files=>setReceiving({...receiving,files})} label="Supplier Invoice / Delivery Receipt"/>
        <label className="form-field"><span>Receiving Notes</span><textarea rows="2" value={receiving.notes} onChange={e=>setReceiving({...receiving,notes:e.target.value})}/></label>
        <div className="modal-actions"><button type="button" className="secondary-button" disabled={busy} onClick={()=>setReceiving(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy?'Posting...':'Post Stock Receipt'}</button></div>
      </form>
    </Modal> : null}
    {confirmation?.action==='receive' ? <ActionConfirmationModal
      title="Confirm Delivery Receiving" subtitle={confirmation.order.code}
      message="Post this delivery to Inventory and Accounts Payable? The receipt is recorded under your signed-in account and may require an approval-controlled reversal if entered incorrectly."
      details={[{label:'Supplier',value:confirmation.order.supplier_name},{label:'Delivery Date',value:formatDate(receiving?.received_date)},{label:'Items',value:String(receiving?.lines.filter(line=>Number(line.purchase_quantity)>0).length||0)}]}
      confirmLabel="Confirm & Post Receipt" busy={busy}
      onCancel={()=>setConfirmation(null)} onConfirm={executeReceive}
    /> : null}
    {confirmation?.action==='cancel' ? <Modal title="Cancel Purchase Order" subtitle={confirmation.order.code} onClose={()=>!busy&&setConfirmation(null)}>
      <label className="form-field"><span>Cancellation Reason</span><textarea required minLength={5} rows={3} value={cancelReason} onChange={e=>setCancelReason(e.target.value)} placeholder="Enter the reason for cancellation (minimum 5 characters)"/></label>
      <p className="purchase-confirm-note">This action will cancel the Purchase Order. Posted deliveries cannot be cancelled through this action.</p>
      <div className="modal-actions"><button type="button" className="secondary-button" disabled={busy} onClick={()=>setConfirmation(null)}>Back</button><button type="button" className="danger-button" disabled={busy||cancelReason.trim().length<5} onClick={executeTransition}>{busy?'Cancelling...':'Confirm Cancellation'}</button></div>
    </Modal> : null}
    {confirmation && confirmation.action!=='cancel' ? <ActionConfirmationModal
      title={confirmation.action==='approve'?'Approve Purchase Order':'Submit Purchase Order'}
      subtitle={confirmation.order.code}
      message={confirmation.action==='approve'?'Approval authorizes future delivery receiving. Stock and Accounts Payable will be posted when goods are received.':'Submit this Purchase Order for approval?'}
      details={[{label:'Supplier',value:confirmation.order.supplier_name},{label:'Total',value:money(confirmation.order.total_cost)}]}
      confirmLabel={confirmation.action==='approve'?'Approve Purchase Order':'Submit for Approval'} busy={busy}
      onCancel={()=>setConfirmation(null)} onConfirm={executeTransition}
    /> : null}
  </div>
}
