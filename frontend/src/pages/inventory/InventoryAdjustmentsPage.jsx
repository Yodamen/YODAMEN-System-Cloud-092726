import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './inventory.css'

function monthStart() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01` }
function today() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}` }
function dateTime(value) { return value ? new Intl.DateTimeFormat('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(value)) : '—' }

export default function InventoryAdjustmentsPage() {
  const { hasPermission } = useAuth()
  const [filters, setFilters] = useState({ branch_id: '', from: monthStart(), to: today(), search: '' })
  const [data, setData] = useState({ branches: [], products: [], requests: [], rows: [] })
  const [adjust, setAdjust] = useState(null)
  const [review, setReview] = useState(null)
  const [reversing, setReversing] = useState(null)
  const [approvalConfirm, setApprovalConfirm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const canCreate = hasPermission('inventory-adjustments', 'CREATE') || hasPermission('inventory-adjustments', 'ADJUST')
  const canApprove = hasPermission('inventory-adjustments', 'APPROVE')
  const canReverse = hasPermission('inventory-adjustments', 'REVERSE')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/inventory/adjustments', {
      params: {
        branch_id: next.branch_id || undefined,
        from: next.from || undefined,
        to: next.to || undefined,
        search: next.search || undefined,
      },
    })
    setData(response.data)
    if (!next.branch_id && response.data.branches?.[0]?.id) {
      setFilters((current) => ({ ...current, branch_id: String(response.data.branches[0].id) }))
    }
  }, [filters])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load inventory adjustments.'))).finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [])

  async function apply(event) {
    event.preventDefault()
    setLoading(true)
    setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load inventory adjustments.')) } finally { setLoading(false) }
  }

  async function reset() {
    const next = { branch_id: filters.branch_id, from: monthStart(), to: today(), search: '' }
    setFilters(next)
    setLoading(true)
    try { await load(next) } finally { setLoading(false) }
  }

  function openAdjustment() {
    setAdjust({ product_id: '', branch_id: filters.branch_id || String(data.branches?.[0]?.id || ''), direction: 'ADD', quantity: '', reason_reference_id: '', reason: '', supporting_files: [] })
  }

  async function saveAdjustment(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const quantity = Math.abs(Number(adjust.quantity || 0))
      const change = adjust.direction === 'REDUCE' ? -quantity : quantity
      const attachmentTokens = await uploadSupportingDocuments(adjust.supporting_files || [], 'inventory-adjustments', 'INVENTORY_ADJUSTMENT_SUPPORT')
      await api.post('/v1/inventory/adjustment-requests', {
        retail_product_id: Number(adjust.product_id),
        branch_id: Number(adjust.branch_id),
        quantity_change: change,
        reason_reference_id: Number(adjust.reason_reference_id),
        reason: adjust.reason,
        attachment_tokens: attachmentTokens,
      })
      setAdjust(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit stock adjustment.'))
    } finally {
      setSaving(false)
    }
  }

  function approveRequest(row) {
    setApprovalConfirm(row)
  }

  async function confirmApproveRequest() {
    if (!approvalConfirm) return
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/inventory/adjustment-requests/${approvalConfirm.id}/approve`, {})
      setApprovalConfirm(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve stock adjustment.'))
    } finally {
      setSaving(false)
    }
  }


  async function requestReversal(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(reversing.supporting_files || [], 'inventory-adjustments', 'INVENTORY_ADJUSTMENT_REVERSAL_SUPPORT')
      await api.post(`/v1/inventory/adjustment-requests/${reversing.id}/reversal-requests`, { reason_reference_id: Number(reversing.reason_reference_id), reason: reversing.reason, attachment_tokens: attachmentTokens })
      setReversing(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to request stock adjustment reversal.'))
    } finally { setSaving(false) }
  }

  async function rejectRequest(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/inventory/adjustment-requests/${review.id}/reject`, { review_reason_reference_id: Number(review.review_reason_reference_id), notes: review.notes })
      setReview(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to reject stock adjustment.'))
    } finally {
      setSaving(false)
    }
  }

  return <div className="page-stack">
    <section className="page-heading live-page-heading">
      <div><h1 className="live-page-title">Inventory Adjustments <PageRefreshButton label="Refresh inventory adjustments" disabled={loading || saving} onRefresh={() => load(filters)} /></h1><p>Manual stock corrections follow a request-and-approval workflow before inventory is changed.</p></div>
      {canCreate ? <button className="primary-button" type="button" onClick={openAdjustment}>Adjust Stock</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="content-card"><form className="inventory-filter-grid inventory-movement-filters" onSubmit={apply}>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e)=>setFilters((c)=>({...c,branch_id:e.target.value}))}>{(data.branches||[]).map((row)=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e)=>setFilters((c)=>({...c,from:e.target.value}))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e)=>setFilters((c)=>({...c,to:e.target.value}))} /></label>
      <label className="form-field"><span>Search</span><input value={filters.search} onChange={(e)=>setFilters((c)=>({...c,search:e.target.value}))} placeholder="Item, reference, reason" /></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
    </form></section>

    <section className="content-card">
      <div className="section-heading compact-section-heading"><div><h2>Adjustment Requests</h2><p>Pending requests do not affect stock until approved.</p></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Request</th><th>Type</th><th>Item</th><th>Branch</th><th>Change</th><th>Reason</th><th>Requested By</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{(data.requests||[]).map((row)=><tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{dateTime(row.created_at)}</span></td><td><span className={`status-pill ${row.request_type==='REVERSAL'?'danger':'neutral'}`}>{row.request_type==='REVERSAL'?'Reversal':'Adjustment'}</span></td><td><strong>{row.product?.name}</strong><span className="table-subline">{row.product?.sku}</span></td><td>{row.branch?.name||'—'}</td><td><strong>{row.quantity_change>0?'+':''}{row.quantity_change}</strong> {row.product?.unit||''}</td><td><strong>{row.reason_category?.name || '—'}</strong><span className="table-subline">{row.reason || '—'}</span></td><td>{row.requested_by||'—'}</td><td><span className={`status-pill ${row.status==='APPROVED'?'success':row.status==='REJECTED'?'danger':'warning'}`}>{row.status}</span>{row.reviewed_by?<span className="table-subline">By {row.reviewed_by}</span>:null}</td><td className="action-cell"><ActionMenu items={[
        canApprove && row.status==='PENDING' ? { label: 'Approve', onClick: () => approveRequest(row) } : null,
        canApprove && row.status==='PENDING' ? { label: 'Reject', onClick: () => setReview({ ...row, review_reason_reference_id: '', notes: '' }) } : null,
        canReverse && row.request_type!=='REVERSAL' && row.status==='APPROVED' ? { label: 'Request Reversal', onClick: () => setReversing({ ...row, reason_reference_id: '', reason: '', supporting_files: [] }) } : null,
      ]} /></td></tr>)}{!loading && !(data.requests||[]).length?<tr><td colSpan="9"><div className="empty-state">No stock adjustment requests found.</div></td></tr>:null}</tbody></table></div>
    </section>

    <section className="content-card">
      <div className="section-heading compact-section-heading"><div><h2>Posted Adjustment Log</h2><p>Approved adjustments and approved reversals with their before-and-after stock balances.</p></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Date / Time</th><th>Type</th><th>Item</th><th>Branch</th><th>Change</th><th>Before</th><th>After</th><th>Cost Snapshot</th><th>Reference</th><th>Reason</th></tr></thead><tbody>{(data.rows||[]).map((row)=><tr key={row.id}><td>{dateTime(row.movement_at)}</td><td><span className={`status-pill ${row.movement_type==='ADJUSTMENT_REVERSAL'?'danger':'neutral'}`}>{row.movement_type==='ADJUSTMENT_REVERSAL'?'Reversal':'Adjustment'}</span></td><td><strong>{row.product?.name}</strong><span className="table-subline">{row.product?.sku}</span></td><td>{row.branch?.name||'—'}</td><td><strong>{row.quantity_change>0?'+':''}{row.quantity_change}</strong></td><td>{row.quantity_before}</td><td>{row.quantity_after}</td><td>{formatPeso(row.unit_cost_snapshot||0)}</td><td>{row.reference_code||'—'}</td><td>{row.reason||'—'}</td></tr>)}{!loading && !(data.rows||[]).length?<tr><td colSpan="10"><div className="empty-state">No approved inventory adjustments found.</div></td></tr>:null}</tbody></table></div>
    </section>

    {adjust ? <Modal title="Request Stock Adjustment" subtitle="The request will not change stock until an authorized approver posts it." onClose={()=>setAdjust(null)}><form className="form-grid" onSubmit={saveAdjustment}>
      <label className="form-field form-span-2"><span>Item Name</span><SearchableSelect required value={adjust.product_id} onChange={(e)=>setAdjust({...adjust,product_id:e.target.value})}><option value="">Select inventory item</option>{(data.products||[]).map((row)=><option key={row.id} value={row.id}>{row.label}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Branch</span><SearchableSelect required value={adjust.branch_id} onChange={(e)=>setAdjust({...adjust,branch_id:e.target.value})}>{(data.branches||[]).map((row)=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Add or Reduce</span><SearchableSelect value={adjust.direction} onChange={(e)=>setAdjust({...adjust,direction:e.target.value})}><option value="ADD">Add</option><option value="REDUCE">Reduce</option></SearchableSelect></label>
      <label className="form-field inventory-adjustment-qty"><span>Quantity</span><input type="number" min="0.0001" step="0.0001" required value={adjust.quantity} onChange={(e)=>setAdjust({...adjust,quantity:e.target.value})} /></label>
      <label className="form-field inventory-adjustment-uom"><span>Basic UOM</span><input readOnly value={(data.products||[]).find((row)=>String(row.id)===String(adjust.product_id))?.unit || '—'} /></label>
      <WorkflowReasonSelect context="INVENTORY_ADJUSTMENT" className="form-span-2" value={adjust.reason_reference_id} onChange={(e)=>setAdjust({...adjust,reason_reference_id:e.target.value})} /><label className="form-field form-span-2"><span>Details</span><textarea required rows="3" value={adjust.reason} onChange={(e)=>setAdjust({...adjust,reason:e.target.value})} placeholder="Describe what happened and why the stock must be adjusted." /></label>
      <div className="form-span-2"><SupportingDocumentField files={adjust.supporting_files || []} onChange={(files)=>setAdjust({...adjust,supporting_files:files})} label="Supporting Document" hint="Optional. Attach count sheets, damage photos or other evidence supporting the adjustment." /></div>
      <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={()=>setAdjust(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving?'Submitting...':'Submit for Approval'}</button></div>
    </form></Modal> : null}


    {reversing ? <Modal title={`Request Reversal · ${reversing.code}`} subtitle="The reversal will not affect stock until approved." onClose={() => setReversing(null)}><form onSubmit={requestReversal}><WorkflowReasonSelect context="INVENTORY_ADJUSTMENT_REVERSAL" value={reversing.reason_reference_id} onChange={(e)=>setReversing({...reversing,reason_reference_id:e.target.value})} /><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversing.reason} onChange={(e)=>setReversing({...reversing,reason:e.target.value})} /></label><SupportingDocumentField files={reversing.supporting_files || []} onChange={(files)=>setReversing({...reversing,supporting_files:files})} label="Supporting Document" hint="Optional. Attach evidence supporting this reversal request." /><div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setReversing(null)}>Cancel</button><button className="danger-button" disabled={saving}>Submit Reversal</button></div></form></Modal> : null}

    {approvalConfirm ? <ActionConfirmationModal
      title="Approve Stock Adjustment"
      subtitle={approvalConfirm.code}
      message="This approval will post the inventory movement and update the item's stock balance."
      details={[
        { label: 'Item', value: approvalConfirm.product?.name || '—' },
        { label: 'Quantity Change', value: `${Number(approvalConfirm.quantity_change || 0) > 0 ? '+' : ''}${approvalConfirm.quantity_change || 0} ${approvalConfirm.product?.unit || ''}` },
        { label: 'Branch', value: approvalConfirm.branch?.name || '—' },
      ]}
      confirmLabel="Approve & Post"
      busy={saving}
      onCancel={() => setApprovalConfirm(null)}
      onConfirm={confirmApproveRequest}
    /> : null}

    {review ? <Modal title={`Reject ${review.code}`} subtitle={`${review.product?.name || 'Stock adjustment'} · ${review.quantity_change > 0 ? '+' : ''}${review.quantity_change}`} onClose={()=>setReview(null)}><form className="form-grid" onSubmit={rejectRequest}>
      <WorkflowReasonSelect context="APPROVAL_REJECTION" className="form-span-2" label="Rejection Category" value={review.review_reason_reference_id} onChange={(event)=>setReview((current)=>({...current,review_reason_reference_id:event.target.value}))} /><label className="form-field form-span-2"><span>Rejection Details</span><textarea required rows="4" value={review.notes} onChange={(event)=>setReview((current)=>({...current,notes:event.target.value}))} /></label>
      <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={()=>setReview(null)}>Cancel</button><button className="danger-button" disabled={saving}>{saving?'Rejecting...':'Reject Request'}</button></div>
    </form></Modal> : null}
  </div>
}
