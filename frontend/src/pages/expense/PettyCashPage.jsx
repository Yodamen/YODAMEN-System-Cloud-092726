import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import { useAuth } from '../../context/AuthContext'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './accounting-expense.css'

const isoToday = () => {
  const date = new Date()
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const monthStart = () => `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-01`
const peso = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
const dateLabel = (value) => value ? new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—'

function liquidationStatusClass(status) {
  if (status === 'BALANCED') return 'success'
  if (status === 'SHORT') return 'danger'
  if (status === 'OVER') return 'warning'
  return 'neutral'
}

export default function PettyCashPage() {
  const { hasPermission, user } = useAuth()
  const [data, setData] = useState({ rows: [], summary: {}, options: {}, liquidations: [] })
  const [filters, setFilters] = useState({ from: monthStart(), to: isoToday(), branch_id: '' })
  const [replenishing, setReplenishing] = useState(null)
  const [liquidating, setLiquidating] = useState(null)
  const [reversing, setReversing] = useState(null)
  const [reversalApproval, setReversalApproval] = useState(null)
  const [reversalReject, setReversalReject] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/petty-cash', { params: { ...filters, branch_id: filters.branch_id || undefined } })
    setData(response.data)
  }, [filters])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error))).finally(() => setLoading(false))
  }, [])

  function defaultBranchId() {
    return user?.default_branch?.id || data.options?.branches?.[0]?.id || ''
  }

  function openReplenishment() {
    setMessage('')
    setReplenishing({
      branch_id: defaultBranchId(),
      transaction_date: isoToday(),
      funding_account_id: data.options?.funding_sources?.[0]?.id ? String(data.options.funding_sources[0].id) : '',
      amount: '',
      reference: '',
      description: '',
      supporting_files: [],
    })
  }

  async function saveReplenishment(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(replenishing.supporting_files || [], 'petty-cash', 'PETTY_CASH_REPLENISHMENT_SUPPORT')
      await api.post('/v1/petty-cash', {
        branch_id: Number(replenishing.branch_id),
        transaction_date: replenishing.transaction_date,
        transaction_type: 'REPLENISHMENT',
        direction: 'IN',
        funding_account_id: Number(replenishing.funding_account_id),
        amount: Number(replenishing.amount),
        reference: replenishing.reference || null,
        description: replenishing.description || null,
        attachment_tokens: attachmentTokens,
      })
      setReplenishing(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to replenish Petty Cash.'))
    } finally {
      setSaving(false)
    }
  }

  async function fetchLiquidationPreview(branchId, liquidationDate) {
    if (!branchId || !liquidationDate) return null
    const response = await api.get('/v1/petty-cash/liquidation-preview', {
      params: { branch_id: Number(branchId), liquidation_date: liquidationDate },
    })
    return response.data.preview
  }

  async function openLiquidation() {
    const branchId = defaultBranchId()
    const liquidationDate = isoToday()
    setMessage('')
    try {
      const preview = await fetchLiquidationPreview(branchId, liquidationDate)
      setLiquidating({ branch_id: String(branchId), liquidation_date: liquidationDate, actual_cash_count: '', notes: '', preview })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to prepare Petty Cash reconciliation.'))
    }
  }

  async function updateLiquidationScope(patch) {
    const next = { ...liquidating, ...patch }
    setLiquidating(next)
    try {
      const preview = await fetchLiquidationPreview(next.branch_id, next.liquidation_date)
      setLiquidating((current) => current ? { ...current, preview } : current)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh reconciliation balance.'))
    }
  }

  const liquidationVariance = useMemo(() => {
    if (!liquidating || liquidating.actual_cash_count === '') return null
    return Number(liquidating.actual_cash_count || 0) - Number(liquidating.preview?.book_balance || 0)
  }, [liquidating])

  async function saveLiquidation(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post('/v1/petty-cash/liquidations', {
        branch_id: Number(liquidating.branch_id),
        liquidation_date: liquidating.liquidation_date,
        actual_cash_count: Number(liquidating.actual_cash_count),
        notes: liquidating.notes || null,
      })
      setLiquidating(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to record Petty Cash reconciliation.'))
    } finally {
      setSaving(false)
    }
  }

  async function reverse(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/petty-cash/${reversing.reversible_transaction_id}/reverse`, { reason_reference_id: Number(reversing.reason_reference_id), reason: reversing.reason })
      setReversing(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit Petty Cash reversal request.'))
    } finally {
      setSaving(false)
    }
  }

  async function approveReversal() {
    if (!reversalApproval) return
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/petty-cash/reversal-requests/${reversalApproval.id}/approve`, {})
      setReversalApproval(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to approve Petty Cash reversal.')) }
    finally { setSaving(false) }
  }

  async function rejectReversal(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      await api.post(`/v1/petty-cash/reversal-requests/${reversalReject.id}/reject`, { review_reason_reference_id: Number(reversalReject.review_reason_reference_id), notes: reversalReject.notes })
      setReversalReject(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to reject Petty Cash reversal.')) }
    finally { setSaving(false) }
  }

  return <div className="page-stack accounting-page">
    <section className="page-heading live-page-heading">
      <div>
        <h1 className="live-page-title">Petty Cash <PageRefreshButton label="Refresh petty cash" disabled={loading} onRefresh={load} /></h1>
        <p>Automatic Petty Cash subledger. Expenses, payroll, supplier payments, and other posted transactions appear here whenever Petty Cash is used as the funding source.</p>
      </div>
      {hasPermission('petty-cash', 'CREATE') ? <div className="toolbar-row">
        <details className="petty-cash-action-dropdown">
          <summary className="primary-button petty-cash-action-trigger">Replenish <span aria-hidden="true">▾</span></summary>
          <div className="petty-cash-action-menu">
            <button type="button" onClick={(event) => { event.currentTarget.closest('details')?.removeAttribute('open'); openReplenishment() }}>Replenish</button>
            <button type="button" onClick={(event) => { event.currentTarget.closest('details')?.removeAttribute('open'); openLiquidation() }}>Reconcile</button>
          </div>
        </details>
      </div> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="metric-grid accounting-metric-grid petty-cash-metric-grid">
      <article className="metric-card"><span>Petty Cash Balance</span><strong>{peso(data.summary?.balance)}</strong></article>
      <article className="metric-card"><span>Replenished</span><strong>{peso(data.summary?.replenished)}</strong><small>Selected period</small></article>
      <article className="metric-card"><span>Disbursed</span><strong>{peso(data.summary?.disbursed)}</strong><small>Active Petty Cash outflows</small></article>
      <article className="metric-card"><span>Reversed</span><strong>{peso(data.summary?.reversed)}</strong><small>Reversal value in period</small></article>
      <article className="metric-card"><span>Unreconciled</span><strong>{peso(data.summary?.unliquidated_outflow)}</strong><small>Outflows since last reconciliation</small></article>
    </section>

    <section className="content-card"><div className="expense-filter-grid">
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All Branches</option>{(data.options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      <div className="accounting-filter-actions"><button className="secondary-button" type="button" onClick={load}>Apply</button></div>
    </div></section>

    <section className="content-card accounting-table-card">
      <div className="section-heading"><div><h2>Petty Cash Ledger</h2><p>Ledger-derived. Do not re-enter expenses here.</p></div></div>
      <div className="table-wrap"><table className="data-table accounting-table petty-cash-ledger-table"><thead><tr><th>Date</th><th>Source / Reference</th><th>Description</th><th>Counterpart</th><th>Cash In</th><th>Cash Out</th><th>Balance</th><th className="actions-column">Action</th></tr></thead><tbody>
        {(data.rows || []).map((row) => <tr key={row.id}>
          <td>{dateLabel(row.transaction_date)}</td>
          <td><strong>{row.source_label}</strong><span className="table-subline">{row.reference || row.journal_code}</span></td>
          <td><strong>{row.description || '—'}</strong><span className="table-subline">{row.branch?.name || '—'}</span></td>
          <td>{(row.counterpart_accounts || []).map((account) => account.name).join(' + ') || '—'}</td>
          <td>{Number(row.in || 0) > 0 ? peso(row.in) : '—'}</td>
          <td>{Number(row.out || 0) > 0 ? peso(row.out) : '—'}</td>
          <td><strong>{peso(row.balance_after)}</strong></td>
          <td className="action-cell">{row.pending_reversal ? (hasPermission('petty-cash', 'APPROVE_REVERSAL') ? <ActionMenu items={[{ label: 'Approve Reversal', onClick: () => setReversalApproval(row.pending_reversal) }, { label: 'Reject Reversal', onClick: () => setReversalReject({ ...row.pending_reversal, review_reason_reference_id: '', notes: '' }) }]} /> : <span className="status-pill warning">Pending Approval</span>) : row.reversible_transaction_id && hasPermission('petty-cash', 'REVERSE') ? <button className="secondary-button compact-button" type="button" onClick={() => setReversing({ ...row, reason_reference_id: '', reason: '' })}>Request Reversal</button> : '—'}</td>
        </tr>)}
        {!(data.rows || []).length ? <tr><td colSpan="8" className="empty-table-cell">No Petty Cash ledger activity found.</td></tr> : null}
      </tbody></table></div>
    </section>

    <section className="content-card accounting-table-card">
      <div className="section-heading"><div><h2>Reconciliation History</h2><p>Reconciles recorded Petty Cash activity against the actual physical cash count without re-posting the underlying expenses.</p></div></div>
      <div className="table-wrap"><table className="data-table accounting-table"><thead><tr><th>Date</th><th>Reconciliation</th><th>Branch</th><th>Period</th><th>Outflows Covered</th><th>Book Balance</th><th>Actual Cash</th><th>Variance</th><th>Status</th></tr></thead><tbody>
        {(data.liquidations || []).map((row) => <tr key={row.id}><td>{dateLabel(row.liquidation_date)}</td><td><strong>{row.code}</strong><span className="table-subline">{row.created_by || '—'}</span></td><td>{row.branch?.name || '—'}</td><td>{row.period_from ? `${dateLabel(row.period_from)} → ${dateLabel(row.period_to)}` : dateLabel(row.period_to)}</td><td>{peso(row.covered_outflow)}</td><td>{peso(row.book_balance)}</td><td>{peso(row.actual_cash_count)}</td><td>{peso(row.variance_amount)}</td><td><span className={`status-pill ${liquidationStatusClass(row.reconciliation_status)}`}>{row.reconciliation_status}</span></td></tr>)}
        {!(data.liquidations || []).length ? <tr><td colSpan="9" className="empty-table-cell">No Petty Cash reconciliations yet.</td></tr> : null}
      </tbody></table></div>
    </section>

    {replenishing ? <Modal title="Replenish Petty Cash" subtitle="Adds funds to Petty Cash. Expense and payroll cash-outs must be recorded in their originating modules." onClose={() => setReplenishing(null)}><form onSubmit={saveReplenishment} className="page-stack">
      <div className="form-grid petty-cash-replenishment-grid">
        <label className="form-field"><span>Date</span><input type="date" required value={replenishing.transaction_date} onChange={(e) => setReplenishing((c) => ({ ...c, transaction_date: e.target.value }))} /></label>
        <label className="form-field"><span>Branch</span><SearchableSelect required value={replenishing.branch_id} onChange={(e) => setReplenishing((c) => ({ ...c, branch_id: e.target.value }))}>{(data.options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
        <label className="form-field accounting-span-2"><span>Funding Source</span><SearchableSelect required value={replenishing.funding_account_id} onChange={(e) => setReplenishing((c) => ({ ...c, funding_account_id: e.target.value }))}><option value="">Select funding source</option>{(data.options?.funding_sources || []).map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Amount</span><input type="number" step="0.01" min="0.01" required value={replenishing.amount} onChange={(e) => setReplenishing((c) => ({ ...c, amount: e.target.value }))} /></label>
        <label className="form-field"><span>Reference</span><input value={replenishing.reference} onChange={(e) => setReplenishing((c) => ({ ...c, reference: e.target.value }))} placeholder="Optional" /></label>
        <label className="form-field accounting-span-2"><span>Remarks</span><textarea rows="3" value={replenishing.description} onChange={(e) => setReplenishing((c) => ({ ...c, description: e.target.value }))} /></label>
        <div className="accounting-span-2"><SupportingDocumentField files={replenishing.supporting_files || []} onChange={(files) => setReplenishing((c) => ({ ...c, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach the transfer slip, release proof or replenishment acknowledgment." /></div>
      </div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReplenishing(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Posting...' : 'Post Replenishment'}</button></div>
    </form></Modal> : null}

    {liquidating ? <Modal wide title="Reconcile Petty Cash" subtitle="Compare the accounting book balance with the actual physical cash count. This does not duplicate Expense, Payroll, AP, or Stock Received entries." onClose={() => setLiquidating(null)}><form onSubmit={saveLiquidation} className="page-stack">
      <div className="form-grid petty-cash-liquidation-header">
        <label className="form-field"><span>Branch</span><SearchableSelect required value={liquidating.branch_id} onChange={(e) => updateLiquidationScope({ branch_id: e.target.value })}>{(data.options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Reconciliation Date</span><input type="date" required value={liquidating.liquidation_date} onChange={(e) => updateLiquidationScope({ liquidation_date: e.target.value })} /></label>
      </div>
      <div className="petty-liquidation-summary">
        <div><span>Period</span><strong>{liquidating.preview?.period_from ? `${dateLabel(liquidating.preview.period_from)} → ${dateLabel(liquidating.preview.period_to)}` : dateLabel(liquidating.preview?.period_to)}</strong></div>
        <div><span>Recorded Outflows</span><strong>{peso(liquidating.preview?.covered_outflow)}</strong></div>
        <div><span>Book Balance</span><strong>{peso(liquidating.preview?.book_balance)}</strong></div>
        <div><span>Entries Covered</span><strong>{Number(liquidating.preview?.transaction_count || 0)}</strong></div>
      </div>
      <div className="petty-cash-liquidation-count-grid">
        <label className="petty-liquidation-count-card"><span>Actual Cash Count</span><input type="number" min="0" step="0.01" required value={liquidating.actual_cash_count} onChange={(e) => setLiquidating((c) => ({ ...c, actual_cash_count: e.target.value }))} /></label>
        <div className="petty-liquidation-variance"><span>Difference</span><strong>{liquidationVariance === null ? '—' : peso(liquidationVariance)}</strong><small>{liquidationVariance === null ? 'Enter the actual count.' : Math.abs(liquidationVariance) <= 0.009 ? 'BALANCED' : liquidationVariance < 0 ? 'SHORT' : 'OVER'}</small></div>
      </div>
      <label className="form-field"><span>Notes{liquidationVariance !== null && Math.abs(liquidationVariance) > 0.009 ? ' *' : ''}</span><textarea rows="3" required={liquidationVariance !== null && Math.abs(liquidationVariance) > 0.009} value={liquidating.notes} onChange={(e) => setLiquidating((c) => ({ ...c, notes: e.target.value }))} placeholder="Add explanation when there is a shortage or overage." /></label>
      <div className="form-alert info">Any shortage or overage is recorded as a reconciliation variance only. It does not change the accounting ledger until a separately approved accounting adjustment is posted.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setLiquidating(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving || !liquidating.preview?.transaction_count}>{saving ? 'Saving...' : 'Record Reconciliation'}</button></div>
    </form></Modal> : null}

    {reversing ? <Modal title={`Request Reversal · ${reversing.reference || 'Replenishment'}`} subtitle="The original replenishment remains posted until an authorized approver approves the request." onClose={() => setReversing(null)}><form onSubmit={reverse}><WorkflowReasonSelect context="PETTY_CASH_REVERSAL" value={reversing.reason_reference_id} onChange={(e) => setReversing((c) => ({ ...c, reason_reference_id: e.target.value }))}/><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversing.reason} onChange={(e) => setReversing((c) => ({ ...c, reason: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversing(null)}>Cancel</button><button className="danger-button" type="submit" disabled={saving}>{saving ? 'Submitting...' : 'Submit for Approval'}</button></div></form></Modal> : null}

    {reversalApproval ? <ActionConfirmationModal title="Approve Petty Cash Reversal" subtitle={reversalApproval.code} message="Approval will preserve the original replenishment and post the reversing journal." confirmLabel="Approve & Reverse" busy={saving} onCancel={() => setReversalApproval(null)} onConfirm={approveReversal} /> : null}
    {reversalReject ? <Modal title={`Reject Reversal · ${reversalReject.code}`} onClose={() => setReversalReject(null)}><form onSubmit={rejectReversal}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={reversalReject.review_reason_reference_id} onChange={(e) => setReversalReject({ ...reversalReject, review_reason_reference_id: e.target.value })}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={reversalReject.notes} onChange={(e) => setReversalReject({ ...reversalReject, notes: e.target.value })} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversalReject(null)}>Cancel</button><button className="danger-button" disabled={saving}>Reject Request</button></div></form></Modal> : null}
  </div>
}
