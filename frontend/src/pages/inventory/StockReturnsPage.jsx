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
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './inventory-control.css'

function dateValue(date) { return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}` }
function today() { return dateValue(new Date()) }
function monthStart() { const d = new Date(); return dateValue(new Date(d.getFullYear(),d.getMonth(),1)) }
function dateLabel(value) { if (!value) return '—'; return new Intl.DateTimeFormat('en-PH',{year:'numeric',month:'short',day:'numeric'}).format(new Date(`${value}T00:00:00`)) }
function statusClass(status) { return status === 'APPROVED' ? 'success' : status === 'REJECTED' ? 'danger' : 'warning' }

export default function StockReturnsPage() {
  const { hasPermission } = useAuth()
  const [filters,setFilters] = useState({ branch_id:'', status:'ALL', from:monthStart(), to:today(), search:'' })
  const [data,setData] = useState({ options:{branches:[],receipts:[],receiving_accounts:[]}, returns:[] })
  const [form,setForm] = useState(null)
  const [selected,setSelected] = useState(null)
  const [confirm,setConfirm] = useState(null)
  const [review,setReview] = useState(null)
  const [refund,setRefund] = useState(null)
  const [loading,setLoading] = useState(true)
  const [saving,setSaving] = useState(false)
  const [message,setMessage] = useState('')

  const canCreate=hasPermission('stock-returns','CREATE')
  const canApprove=hasPermission('stock-returns','APPROVE')
  const canRefund=hasPermission('stock-returns','RECEIVE_REFUND')

  const load=useCallback(async(next=filters)=>{
    const response=await api.get('/v1/inventory/stock-returns',{params:{...next,branch_id:next.branch_id||undefined,status:next.status==='ALL'?undefined:next.status,search:next.search||undefined}})
    setData(response.data)
    if(!next.branch_id && response.data.options?.branches?.[0]?.id) setFilters((c)=>({...c,branch_id:String(response.data.options.branches[0].id)}))
  },[filters])
  useEffect(()=>{ load().catch(e=>setMessage(getApiErrorMessage(e,'Unable to load stock returns.'))).finally(()=>setLoading(false)) },[])

  const sourceReceipt=useMemo(()=>data.options?.receipts?.find(r=>String(r.id)===String(form?.inventory_receipt_id)),[data.options?.receipts,form?.inventory_receipt_id])
  function openCreate(){ setForm({inventory_receipt_id:'',branch_id:filters.branch_id||'',return_date:today(),reason_reference_id:'',reason:'',lines:[],supporting_files:[]}) }
  function chooseReceipt(id){ const r=(data.options?.receipts||[]).find(x=>String(x.id)===String(id)); setForm(c=>({...c,inventory_receipt_id:id,branch_id:r?String(r.branch_id):c.branch_id,lines:(r?.lines||[]).map(line=>({inventory_receipt_line_id:line.id,quantity_returned:''}))})) }
  function lineQty(id,value){ setForm(c=>({...c,lines:c.lines.map(x=>x.inventory_receipt_line_id===id?{...x,quantity_returned:value}:x)})) }
  async function save(event){event.preventDefault();setSaving(true);setMessage('');try{const attachmentTokens=await uploadSupportingDocuments(form.supporting_files||[],'stock-returns','STOCK_RETURN_SUPPORT');const {supporting_files,...payload}=form;await api.post('/v1/inventory/stock-returns',{...payload,reason_reference_id:Number(form.reason_reference_id),attachment_tokens:attachmentTokens,inventory_receipt_id:Number(form.inventory_receipt_id),branch_id:Number(form.branch_id),lines:form.lines.filter(x=>Number(x.quantity_returned)>0).map(x=>({...x,quantity_returned:Number(x.quantity_returned)}))});setForm(null);await load(filters)}catch(e){setMessage(getApiErrorMessage(e,'Unable to submit Stock Return.'))}finally{setSaving(false)}}
  async function approve(){ if(!confirm)return;setSaving(true);setMessage('');try{await api.post(`/v1/inventory/stock-returns/${confirm.id}/approve`,{});setConfirm(null);await load(filters)}catch(e){setMessage(getApiErrorMessage(e,'Unable to approve Stock Return.'))}finally{setSaving(false)}}
  async function reject(event){event.preventDefault();setSaving(true);setMessage('');try{await api.post(`/v1/inventory/stock-returns/${review.id}/reject`,{review_reason_reference_id:Number(review.review_reason_reference_id),notes:review.notes});setReview(null);await load(filters)}catch(e){setMessage(getApiErrorMessage(e,'Unable to reject Stock Return.'))}finally{setSaving(false)}}
  async function receiveRefund(event){event.preventDefault();setSaving(true);setMessage('');try{const attachmentTokens=await uploadSupportingDocuments(refund.supporting_files||[],'stock-returns','SUPPLIER_REFUND_PROOF');if(!attachmentTokens.length) throw new Error('Supplier refund proof is required.');await api.post(`/v1/inventory/stock-returns/${refund.id}/refunds`,{refund_date:refund.refund_date,receiving_account_id:Number(refund.receiving_account_id),amount:Number(refund.amount),reference:refund.reference||null,attachment_tokens:attachmentTokens});setRefund(null);await load(filters)}catch(e){setMessage(getApiErrorMessage(e,e?.message||'Unable to record supplier refund.'))}finally{setSaving(false)}}
  async function apply(event){event.preventDefault();setLoading(true);try{await load(filters)}catch(e){setMessage(getApiErrorMessage(e,'Unable to load stock returns.'))}finally{setLoading(false)}}

  return <div className="page-stack">
    <section className="page-heading live-page-heading"><div><h1 className="live-page-title">Stock Returns <PageRefreshButton label="Refresh stock returns" disabled={loading||saving} onRefresh={()=>load(filters)} /></h1><p>Return received inventory to suppliers through an approval workflow. Paid returns create a Supplier Refund Receivable until the supplier refund is received.</p></div>{canCreate?<button className="primary-button" type="button" onClick={openCreate}>New Stock Return</button>:null}</section>
    {message?<div className="form-alert error">{message}</div>:null}
    <section className="content-card"><form className="inventory-control-filters stock-return-filter-grid" onSubmit={apply}>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={e=>setFilters(c=>({...c,branch_id:e.target.value}))}><option value="">All Branches</option>{(data.options?.branches||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={e=>setFilters(c=>({...c,status:e.target.value}))}><option value="ALL">All</option><option value="PENDING">Pending</option><option value="APPROVED">Approved</option><option value="REJECTED">Rejected</option></SearchableSelect></label>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={e=>setFilters(c=>({...c,from:e.target.value}))}/></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={e=>setFilters(c=>({...c,to:e.target.value}))}/></label>
      <label className="form-field"><span>Search</span><input value={filters.search} onChange={e=>setFilters(c=>({...c,search:e.target.value}))} placeholder="Return, receipt, supplier"/></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button></div>
    </form></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Return</th><th>Original Receipt</th><th>Supplier</th><th>Branch</th><th>Value</th><th>AP Credit</th><th>Refund Due</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>
      {(data.returns||[]).map(row=><tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{dateLabel(row.return_date)}</span></td><td>{row.receipt?.code||'—'}</td><td>{row.supplier?.name||'—'}</td><td>{row.branch?.name||'—'}</td><td>{formatPeso(row.total_value)}</td><td>{formatPeso(row.ap_credit_amount)}</td><td><strong>{formatPeso(row.refund_remaining)}</strong>{row.refund_received>0?<span className="table-subline">Received {formatPeso(row.refund_received)}</span>:null}</td><td><span className={`status-pill ${statusClass(row.status)}`}>{row.status}</span></td><td className="action-cell"><ActionMenu items={[
        {label:'View Details',onClick:()=>setSelected(row)},
        canApprove&&row.status==='PENDING'?{label:'Approve',onClick:()=>setConfirm(row)}:null,
        canApprove&&row.status==='PENDING'?{label:'Reject',onClick:()=>setReview({...row,review_reason_reference_id:'',notes:''})}:null,
        canRefund&&row.status==='APPROVED'&&Number(row.refund_remaining)>0.009?{label:'Receive Supplier Refund',onClick:()=>setRefund({...row,refund_date:today(),receiving_account_id:'',amount:String(row.refund_remaining),reference:'',supporting_files:[]})}:null,
      ]}/></td></tr>)}
      {!loading&&!(data.returns||[]).length?<tr><td colSpan="9"><div className="empty-state">No stock returns found.</div></td></tr>:null}
    </tbody></table></div></section>

    {form?<Modal wide title="New Stock Return" subtitle="The return will not affect stock or accounting until approved." onClose={()=>setForm(null)}><form className="page-stack" onSubmit={save}>
      <div className="form-grid"><label className="form-field form-span-2"><span>Original Stock Receipt</span><SearchableSelect required value={form.inventory_receipt_id} onChange={e=>chooseReceipt(e.target.value)}><option value="">Select receipt</option>{(data.options?.receipts||[]).map(r=><option key={r.id} value={r.id}>{r.code} · {r.supplier} · {dateLabel(r.received_date)}</option>)}</SearchableSelect></label><label className="form-field"><span>Return Date</span><input required type="date" max={today()} value={form.return_date} onChange={e=>setForm({...form,return_date:e.target.value})}/></label><WorkflowReasonSelect context="STOCK_RETURN" className="form-span-2" value={form.reason_reference_id} onChange={e=>setForm({...form,reason_reference_id:e.target.value})}/><label className="form-field form-span-2"><span>Details</span><textarea required minLength="5" rows="3" value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})}/></label></div>
      <SupportingDocumentField files={form.supporting_files||[]} onChange={(files)=>setForm({...form,supporting_files:files})} label="Supporting Document" hint="Optional. Attach a supplier return slip, acknowledgment, courier proof or other supporting document." />
      {sourceReceipt?<div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Returnable Qty</th><th>Unit Cost</th><th>Qty to Return</th></tr></thead><tbody>{sourceReceipt.lines.map(line=><tr key={line.id}><td><strong>{line.name}</strong></td><td>{line.returnable_quantity} {line.unit}</td><td>{formatPeso(line.unit_cost)}</td><td><input className="table-inline-input" type="number" min="0" max={line.returnable_quantity} step="0.0001" value={form.lines.find(x=>x.inventory_receipt_line_id===line.id)?.quantity_returned||''} onChange={e=>lineQty(line.id,e.target.value)} /></td></tr>)}</tbody></table></div>:null}
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>Submit for Approval</button></div>
    </form></Modal>:null}

    {selected?<Modal wide title={`Stock Return · ${selected.code}`} subtitle={`${selected.receipt?.code||''} · ${selected.supplier?.name||''}`} onClose={()=>setSelected(null)}><div className="inventory-detail-grid"><div><span>Status</span><strong>{selected.status}</strong></div><div><span>Total Value</span><strong>{formatPeso(selected.total_value)}</strong></div><div><span>AP Credit</span><strong>{formatPeso(selected.ap_credit_amount)}</strong></div><div><span>Supplier Refund Receivable</span><strong>{formatPeso(selected.supplier_refund_receivable)}</strong></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Qty Returned</th><th>Unit Cost</th><th>Value</th></tr></thead><tbody>{(selected.lines||[]).map(line=><tr key={line.id}><td>{line.name}</td><td>{line.quantity_returned} {line.unit}</td><td>{formatPeso(line.unit_cost_snapshot)}</td><td>{formatPeso(line.line_total)}</td></tr>)}</tbody></table></div>{selected.refunds?.length?<><h3>Supplier Refunds Received</h3><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Receiving Account</th><th>Reference</th><th>Amount</th><th>Received By</th></tr></thead><tbody>{selected.refunds.map(x=><tr key={x.id}><td>{dateLabel(x.refund_date)}</td><td>{x.receiving_account}</td><td>{x.reference||'—'}</td><td>{formatPeso(x.amount)}</td><td>{x.received_by||'—'}</td></tr>)}</tbody></table></div></>:null}<SupportingDocumentList entityType="INVENTORY_STOCK_RETURN" entityId={selected.id} /><div className="modal-actions"><button className="secondary-button" onClick={()=>setSelected(null)}>Close</button></div></Modal>:null}

    {confirm?<ActionConfirmationModal title="Approve Stock Return" subtitle={confirm.code} message="Approval will reduce On Hand inventory and post the supplier return accounting entry." confirmLabel="Approve Return" busy={saving} onCancel={()=>setConfirm(null)} onConfirm={approve}/>:null}
    {review?<Modal title={`Reject ${review.code}`} onClose={()=>setReview(null)}><form onSubmit={reject}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={review.review_reason_reference_id} onChange={e=>setReview({...review,review_reason_reference_id:e.target.value})}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={review.notes} onChange={e=>setReview({...review,notes:e.target.value})}/></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setReview(null)}>Cancel</button><button className="danger-button" disabled={saving}>Reject</button></div></form></Modal>:null}
    {refund?<Modal title={`Receive Supplier Refund · ${refund.code}`} subtitle={`Remaining receivable ${formatPeso(refund.refund_remaining)}`} onClose={()=>setRefund(null)}><form className="form-grid" onSubmit={receiveRefund}><label className="form-field"><span>Refund Date</span><input required type="date" value={refund.refund_date} onChange={e=>setRefund({...refund,refund_date:e.target.value})}/></label><label className="form-field"><span>Receiving Bank / E-Wallet</span><SearchableSelect required value={refund.receiving_account_id} onChange={e=>setRefund({...refund,receiving_account_id:e.target.value})}><option value="">Select account</option>{(data.options?.receiving_accounts||[]).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Amount</span><input required type="number" min="0.01" max={refund.refund_remaining} step="0.01" value={refund.amount} onChange={e=>setRefund({...refund,amount:e.target.value})}/></label><label className="form-field"><span>Reference</span><input value={refund.reference} onChange={e=>setRefund({...refund,reference:e.target.value})}/></label><div className="form-span-2"><SupportingDocumentField required files={refund.supporting_files||[]} onChange={(files)=>setRefund({...refund,supporting_files:files})} label="Refund Proof" hint="Required before recording the supplier refund. Attach the bank/e-wallet credit, supplier confirmation or equivalent proof." /></div><div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={()=>setRefund(null)}>Cancel</button><button className="primary-button" disabled={saving}>Post Refund</button></div></form></Modal>:null}
  </div>
}
