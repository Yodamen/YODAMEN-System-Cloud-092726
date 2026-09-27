import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'
import { useAuth } from '../../context/AuthContext'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'

function localDateKey(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const today = localDateKey()
const emptyRun = { period_start: '', period_end: '', pay_date: today, branch_id: '', notes: '' }
const emptyAdjustment = { personnel_id: '', effective_date: today, adjustment_type: 'OTHER', direction: 'ADD', amount: '', description: '', supporting_files: [] }

function statusClass(status) {
  if (['PAID', 'APPROVED'].includes(status)) return 'success'
  if (status === 'CANCELLED') return 'danger'
  return 'neutral'
}

export default function PayrollPage() {
  const { user, hasPermission } = useAuth()
  const [data, setData] = useState(null)
  const [detail, setDetail] = useState(null)
  const [runForm, setRunForm] = useState(emptyRun)
  const [adjustmentForm, setAdjustmentForm] = useState(emptyAdjustment)
  const [showRun, setShowRun] = useState(false)
  const [showAdjustment, setShowAdjustment] = useState(false)
  const [confirmation, setConfirmation] = useState(null)
  const [adjustmentReversal, setAdjustmentReversal] = useState(null)
  const [adjustmentReversalApproval, setAdjustmentReversalApproval] = useState(null)
  const [adjustmentReversalReject, setAdjustmentReversalReject] = useState(null)
  const [paymentForm, setPaymentForm] = useState(null)
  const [cashAdvanceAction, setCashAdvanceAction] = useState(null)
  const [cashAdvanceReason, setCashAdvanceReason] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/hr/payroll')
    setData(response.data)
  }, [])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Payroll.')))
  }, [load])

  async function openRun(id) {
    setMessage('')
    try {
      const response = await api.get(`/v1/hr/payroll/${id}`)
      setDetail(response.data.run)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Payroll Run.'))
    }
  }

  async function createRun(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    try {
      const response = await api.post('/v1/hr/payroll', {
        ...runForm,
        branch_id: runForm.branch_id ? Number(runForm.branch_id) : null,
        notes: runForm.notes || null,
      })
      setShowRun(false)
      setRunForm(emptyRun)
      setDetail(response.data.run)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to create Payroll Draft.'))
    } finally {
      setBusy(false)
    }
  }

  async function executeRunAction(action, payload = {}) {
    if (!detail) return
    setBusy(true)
    setMessage('')
    try {
      const response = await api.post(`/v1/hr/payroll/${detail.id}/${action}`, payload)
      setDetail(response.data.run)
      setConfirmation(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update Payroll Run.'))
    } finally {
      setBusy(false)
    }
  }

  function requestRunAction(action) {
    if (action === 'mark-paid' && Number(detail?.net_pay || 0) <= 0.004) {
      setConfirmation({
        type: 'run',
        action: 'mark-paid',
        title: 'Close Zero-Pay Payroll',
        message: 'This payroll has zero Net Pay after deductions/recoveries. Mark it Paid without a cash disbursement?',
        confirmLabel: 'Mark Paid',
        details: [
          { label: 'Payroll', value: detail?.code },
          { label: 'Prior Payroll Recovery', value: formatPeso(detail?.payroll_recovery_deductions) },
          { label: 'Net Pay', value: formatPeso(detail?.net_pay) },
        ],
      })
      return
    }
    if (action === 'mark-paid') {
      const preferred = (data?.funding_sources || []).find((row) => String(row.name || '').toLowerCase().includes('bank')) || (data?.funding_sources || [])[0]
      setPaymentForm({ funding_splits: [{ funding_account_id: preferred?.id ? String(preferred.id) : '', amount: String(detail?.net_pay || ''), reference: '' }] })
      return
    }
    const configs = {
      cancel: {
        title: 'Cancel Payroll Draft',
        message: 'Cancel this Payroll Draft and release its reserved source records?',
        confirmLabel: 'Cancel Draft',
        danger: true,
      },
      approve: {
        title: 'Approve Payroll',
        message: 'Approve this payroll? Approved values will be frozen and cannot be recalculated.',
        confirmLabel: 'Approve Payroll',
      },
    }
    const config = configs[action]
    if (!config || !detail) return
    setConfirmation({
      type: 'run',
      action,
      ...config,
      details: [
        { label: 'Payroll', value: detail.code },
        { label: 'Period', value: `${formatDisplayDate(detail.period_start)} → ${formatDisplayDate(detail.period_end)}` },
        { label: 'Statutory Deductions', value: formatPeso(detail.statutory_employee_deductions) },
        { label: 'Net Pay', value: formatPeso(detail.net_pay) },
      ],
    })
  }

  async function submitPayrollPayment(event) {
    event.preventDefault()
    if (!detail || !paymentForm) return
    setBusy(true)
    setMessage('')
    try {
      const response = await api.post(`/v1/hr/payroll/${detail.id}/mark-paid`, {
        funding_splits: paymentForm.funding_splits.map((split) => ({
          funding_account_id: Number(split.funding_account_id),
          amount: Number(split.amount),
          reference: split.reference || null,
        })),
      })
      setDetail(response.data.run)
      setPaymentForm(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to mark Payroll Paid.'))
    } finally {
      setBusy(false)
    }
  }

  async function createAdjustment(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(adjustmentForm.supporting_files || [], 'payroll', 'PAYROLL_ADJUSTMENT_SUPPORT')
      const { supporting_files, ...adjustmentPayload } = adjustmentForm
      await api.post('/v1/hr/payroll-adjustments', {
        ...adjustmentPayload,
        attachment_tokens: attachmentTokens,
        personnel_id: Number(adjustmentForm.personnel_id),
        amount: (adjustmentForm.direction === 'DEDUCT' ? -1 : 1) * Math.abs(Number(adjustmentForm.amount)),
        reason: adjustmentForm.description,
      })
      setShowAdjustment(false)
      setAdjustmentForm(emptyAdjustment)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to create Payroll adjustment.'))
    } finally {
      setBusy(false)
    }
  }

  function requestReverseAdjustment(row) {
    setAdjustmentReversal({ ...row, reason_reference_id: '', reversal_reason: '', supporting_files: [] })
  }

  async function submitAdjustmentReversal(event) {
    event.preventDefault()
    if (!adjustmentReversal) return
    setBusy(true)
    setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(adjustmentReversal.supporting_files || [], 'payroll', 'PAYROLL_ADJUSTMENT_REVERSAL_SUPPORT')
      await api.post(`/v1/hr/payroll-adjustments/${adjustmentReversal.id}/reverse`, { reason_reference_id: Number(adjustmentReversal.reason_reference_id), reason: adjustmentReversal.reversal_reason, attachment_tokens: attachmentTokens })
      setAdjustmentReversal(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit payroll adjustment reversal request.'))
    } finally {
      setBusy(false)
    }
  }

  async function approveAdjustmentReversal() {
    if (!adjustmentReversalApproval) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/hr/payroll-adjustment-reversal-requests/${adjustmentReversalApproval.id}/approve`)
      setAdjustmentReversalApproval(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve payroll adjustment reversal.'))
    } finally {
      setBusy(false)
    }
  }

  async function rejectAdjustmentReversal(event) {
    event.preventDefault()
    if (!adjustmentReversalReject) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/hr/payroll-adjustment-reversal-requests/${adjustmentReversalReject.id}/reject`, { review_reason_reference_id: Number(adjustmentReversalReject.review_reason_reference_id), notes: adjustmentReversalReject.notes })
      setAdjustmentReversalReject(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to reject payroll adjustment reversal.'))
    } finally {
      setBusy(false)
    }
  }

  async function confirmCurrentAction() {
    if (!confirmation) return
    if (confirmation.type === 'run') {
      await executeRunAction(confirmation.action)
    }
  }

  const payrollFundingTotal = (paymentForm?.funding_splits || []).reduce((sum, split) => sum + (Number(split.amount) || 0), 0)
  const payrollFundingBalance = Number(detail?.net_pay || 0) - payrollFundingTotal
  const payrollFundingBalanced = Math.abs(payrollFundingBalance) < 0.005 && (paymentForm?.funding_splits || []).length > 0

  function updatePayrollFundingSplit(index, field, value) {
    setPaymentForm((current) => ({
      ...current,
      funding_splits: current.funding_splits.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row),
    }))
  }

  function addPayrollFundingSplit() {
    setPaymentForm((current) => current && current.funding_splits.length < 5
      ? { ...current, funding_splits: [...current.funding_splits, { funding_account_id: '', amount: '', reference: '' }] }
      : current)
  }

  function removePayrollFundingSplit(index) {
    setPaymentForm((current) => current && current.funding_splits.length > 1
      ? { ...current, funding_splits: current.funding_splits.filter((_, rowIndex) => rowIndex !== index) }
      : current)
  }

  const canManageCashAdvanceWaiver = Boolean(user?.role?.code === 'ROLE-MGR')
  const canApproveAdjustmentReversal = hasPermission('payroll', 'APPROVE_REVERSAL')
  const blockingExceptions = (detail?.approval_exceptions || []).filter((row) => row.severity === 'BLOCKING')
  const recalculationRequired = blockingExceptions.some((row) => row.code === 'DRAFT_OUT_OF_DATE')

  function requestCashAdvanceAction(line, option, mode) {
    setCashAdvanceReason('')
    setCashAdvanceAction({ line, option, mode })
  }

  async function submitCashAdvanceAction(event) {
    event.preventDefault()
    if (!detail || !cashAdvanceAction) return
    setBusy(true)
    setMessage('')
    try {
      const endpoint = cashAdvanceAction.mode === 'WAIVE' ? 'waive' : 'restore'
      const response = await api.post(`/v1/hr/payroll/${detail.id}/cash-advances/${cashAdvanceAction.option.cash_advance_id}/${endpoint}`, { reason: cashAdvanceReason })
      const refreshed = await api.get(`/v1/hr/payroll/${detail.id}`)
      setDetail(refreshed.data.run || response.data.run)
      setCashAdvanceAction(null)
      setCashAdvanceReason('')
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update cash advance deduction.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page-stack">
      <div className="page-heading"><div><p className="eyebrow">HR & Payroll</p><h1 className="live-page-title">Payroll <PageRefreshButton label="Refresh payroll" onRefresh={load} /></h1><p>Draft, review, approve, and mark payroll paid without rewriting approved history.</p><div className="toolbar-row payroll-heading-actions"><button className="primary-button" type="button" onClick={() => setShowRun(true)}>Payroll Draft</button><button className="secondary-button" type="button" onClick={() => setShowAdjustment(true)}>Adjustment</button></div></div></div>
      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <div className="section-heading"><div><h2>Payroll Runs</h2></div></div>
        <div className="table-wrap"><table className="data-table"><thead><tr><th>Payroll</th><th>Period</th><th>Pay Date</th><th>Gross</th><th>Adjustments</th><th>Cash Advance</th><th>Prior Recovery</th><th>Statutory</th><th>Net Pay</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{(data?.runs || []).map((row) => <tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{row.lines_count ?? 0} personnel</span></td><td>{formatDisplayDate(row.period_start)} → {formatDisplayDate(row.period_end)}</td><td>{formatDisplayDate(row.pay_date)}</td><td>{formatPeso(row.gross_compensation)}</td><td>{formatPeso(row.adjustments_total)}</td><td>{formatPeso(row.cash_advance_deductions)}</td><td>{formatPeso(row.payroll_recovery_deductions)}</td><td>{formatPeso(row.statutory_employee_deductions)}</td><td><strong>{formatPeso(row.net_pay)}</strong></td><td><span className={`status-pill ${statusClass(row.status)}`}>{row.status}</span></td><td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => openRun(row.id) }]} /></td></tr>)}</tbody></table></div>
        {!(data?.runs || []).length ? <div className="empty-state">No payroll runs yet.</div> : null}
      </section>

      <section className="content-card">
        <div className="section-heading"><div><h2>Open Payroll Adjustments</h2><p>Allowances, bonuses, deductions, and corrections that will be picked up by the matching Payroll Draft.</p></div></div>
        <div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Personnel</th><th>Type</th><th>Description</th><th>Amount</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{(data?.adjustments || []).map((row) => {
          const pending = row.pending_reversal
          return <tr key={row.id}><td>{formatDisplayDate(row.effective_date)}</td><td>{row.personnel?.name}</td><td>{row.adjustment_type}</td><td>{row.description}<span className="table-subline">{row.reason}</span></td><td>{formatPeso(row.amount)}</td><td>{pending ? <span className="status-pill warning">Pending Approval</span> : <span className={`status-pill ${row.status === 'REVERSED' ? 'danger' : row.status === 'OPEN' ? 'info' : 'neutral'}`}>{row.status.replaceAll('_', ' ')}</span>}</td><td className="action-cell"><ActionMenu items={pending ? (canApproveAdjustmentReversal ? [
            { label: 'Approve Reversal', onClick: () => setAdjustmentReversalApproval(pending) },
            { label: 'Reject Reversal', onClick: () => setAdjustmentReversalReject({ ...pending, review_reason_reference_id: '', notes: '' }) },
          ] : []) : [row.status === 'OPEN' && hasPermission('payroll', 'CREATE') ? { label: 'Request Reversal', onClick: () => requestReverseAdjustment(row) } : null]} /></td></tr>
        })}</tbody></table></div>
        {!(data?.adjustments || []).length ? <div className="empty-state">No open adjustments.</div> : null}
      </section>

      <section className="content-card">
        <div className="section-heading"><div><h2>Open Payroll Recoveries</h2><p>Post-payroll commission and incentive reversals are carried forward automatically. No manual selection is required.</p></div></div>
        <div className="table-wrap"><table className="data-table"><thead><tr><th>Recovery</th><th>Personnel</th><th>Type</th><th>Source</th><th>Original Payroll</th><th>Total</th><th>Applied</th><th>Remaining</th><th>Status</th></tr></thead><tbody>{(data?.recoveries || []).map((row) => <tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{row.origin_work_date ? formatDisplayDate(row.origin_work_date) : 'Historical payroll'}</span></td><td>{row.personnel?.name || '—'}</td><td>{row.recovery_type}</td><td>{row.source_label || 'Transaction adjustment'}<span className="table-subline">{row.source_action_code || row.reason || '—'}</span></td><td>{row.original_payroll_code || '—'}</td><td>{formatPeso(row.recovery_amount)}</td><td>{formatPeso(row.applied_amount)}</td><td><strong>{formatPeso(row.remaining_amount)}</strong></td><td><span className={`status-pill ${row.status === 'PARTIAL' ? 'warning' : 'info'}`}>{row.status}</span></td></tr>)}</tbody></table></div>
        {!(data?.recoveries || []).length ? <div className="empty-state">No outstanding payroll recoveries.</div> : null}
      </section>

      {showRun ? <Modal title="Create Payroll Draft" subtitle="Sources remain editable only while the run is Draft." onClose={() => setShowRun(false)}><form className="form-grid payroll-run-form" onSubmit={createRun}>
        <label className="form-field"><span>Period Start</span><input type="date" required max={today} value={runForm.period_start} onChange={(e) => setRunForm((c) => ({ ...c, period_start: e.target.value }))} /></label>
        <label className="form-field"><span>Period End</span><input type="date" required min={runForm.period_start || undefined} max={today} value={runForm.period_end} onChange={(e) => setRunForm((c) => ({ ...c, period_end: e.target.value }))} /></label>
        <label className="form-field"><span>Pay Date</span><input type="date" required value={runForm.pay_date} onChange={(e) => setRunForm((c) => ({ ...c, pay_date: e.target.value }))} /></label>
        <label className="form-field"><span>Branch</span><SearchableSelect value={runForm.branch_id} onChange={(e) => setRunForm((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All / No branch restriction</option>{(data?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <label className="form-field full-span payroll-run-notes"><span>Notes</span><textarea rows="4" value={runForm.notes} onChange={(e) => setRunForm((c) => ({ ...c, notes: e.target.value }))} /></label>
        <div className="modal-actions full-span payroll-run-actions"><button className="secondary-button" type="button" onClick={() => setShowRun(false)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? 'Creating...' : 'Create & Calculate'}</button></div>
      </form></Modal> : null}

      {showAdjustment ? <Modal title="Payroll Adjustment" subtitle="Use a future effective date if the original period is already Approved or Paid." onClose={() => setShowAdjustment(false)}><form className="form-grid payroll-adjustment-form" onSubmit={createAdjustment}>
        <label className="form-field"><span>Personnel</span><SearchableSelect required value={adjustmentForm.personnel_id} onChange={(e) => setAdjustmentForm((c) => ({ ...c, personnel_id: e.target.value }))}><option value="">Select personnel</option>{(data?.personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Effective Date</span><input type="date" required value={adjustmentForm.effective_date} onChange={(e) => setAdjustmentForm((c) => ({ ...c, effective_date: e.target.value }))} /></label>
        <label className="form-field"><span>Type</span><SearchableSelect value={adjustmentForm.adjustment_type} onChange={(e) => setAdjustmentForm((c) => ({ ...c, adjustment_type: e.target.value }))}><option>ALLOWANCE</option><option>BONUS</option><option>CORRECTION</option><option>OTHER</option></SearchableSelect></label>
        <label className="form-field"><span>Add / Deduct</span><SearchableSelect value={adjustmentForm.direction} onChange={(e) => setAdjustmentForm((c) => ({ ...c, direction: e.target.value }))}><option value="ADD">Add</option><option value="DEDUCT">Deduct</option></SearchableSelect></label>
        <label className="form-field"><span>Amount</span><input type="number" min="0.01" step="0.01" required value={adjustmentForm.amount} onChange={(e) => setAdjustmentForm((c) => ({ ...c, amount: e.target.value }))} /></label>
        <label className="form-field adjustment-description"><span>Description</span><input required value={adjustmentForm.description} onChange={(e) => setAdjustmentForm((c) => ({ ...c, description: e.target.value }))} /></label>
        <div className="form-span-2"><SupportingDocumentField files={adjustmentForm.supporting_files || []} onChange={(files) => setAdjustmentForm((c) => ({ ...c, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach an approval memo, computation sheet or other payroll adjustment support." /></div>
        <div className="modal-actions payroll-adjustment-actions"><button className="secondary-button" type="button" onClick={() => setShowAdjustment(false)}>Cancel</button><button className="primary-button" disabled={busy}>Save Adjustment</button></div>
      </form></Modal> : null}

      {adjustmentReversal ? <Modal title={`Request Reversal · ${adjustmentReversal.code}`} subtitle="The adjustment remains active until an authorized user approves this request." onClose={() => setAdjustmentReversal(null)}><form className="page-stack" onSubmit={submitAdjustmentReversal}><div className="request-detail-grid"><div><span>Personnel</span><strong>{adjustmentReversal.personnel?.name || '—'}</strong></div><div><span>Amount</span><strong>{formatPeso(adjustmentReversal.amount)}</strong></div></div><WorkflowReasonSelect context="PAYROLL_ADJUSTMENT_REVERSAL" value={adjustmentReversal.reason_reference_id} onChange={(e) => setAdjustmentReversal((current) => ({ ...current, reason_reference_id: e.target.value }))}/><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={adjustmentReversal.reversal_reason} onChange={(e) => setAdjustmentReversal((current) => ({ ...current, reversal_reason: e.target.value }))} /></label><SupportingDocumentField files={adjustmentReversal.supporting_files || []} onChange={(files) => setAdjustmentReversal((current) => ({ ...current, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach evidence supporting this payroll adjustment reversal." /><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setAdjustmentReversal(null)}>Cancel</button><button className="danger-button" disabled={busy}>Submit for Approval</button></div></form></Modal> : null}

      {adjustmentReversalApproval ? <ActionConfirmationModal title="Approve Payroll Adjustment Reversal" message="Approve this request? The adjustment will be reversed and removed from future payroll calculations while preserving its audit history." details={[{ label: 'Request', value: adjustmentReversalApproval.code }, { label: 'Reason', value: adjustmentReversalApproval.reason }]} confirmLabel="Approve Reversal" danger busy={busy} onCancel={() => setAdjustmentReversalApproval(null)} onConfirm={approveAdjustmentReversal} /> : null}

      {adjustmentReversalReject ? <Modal title="Reject Payroll Adjustment Reversal" subtitle={adjustmentReversalReject.code} onClose={() => setAdjustmentReversalReject(null)}><form className="page-stack" onSubmit={rejectAdjustmentReversal}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={adjustmentReversalReject.review_reason_reference_id} onChange={(e) => setAdjustmentReversalReject((current) => ({ ...current, review_reason_reference_id: e.target.value }))}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={adjustmentReversalReject.notes} onChange={(e) => setAdjustmentReversalReject((current) => ({ ...current, notes: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setAdjustmentReversalReject(null)}>Cancel</button><button className="danger-button" disabled={busy}>Reject Request</button></div></form></Modal> : null}

      {detail ? <Modal wide title={detail.code} subtitle={`${formatDisplayDate(detail.period_start)} → ${formatDisplayDate(detail.period_end)} · Pay ${formatDisplayDate(detail.pay_date)}`} onClose={() => setDetail(null)} headerAction={<span className={`status-pill ${statusClass(detail.status)}`}>{detail.status}</span>}>
        <div className="page-stack">
          <section className="metric-grid"><article className="metric-card"><span>Gross</span><strong>{formatPeso(detail.gross_compensation)}</strong></article><article className="metric-card"><span>Adjustments</span><strong>{formatPeso(detail.adjustments_total)}</strong></article><article className="metric-card"><span>Cash Advances</span><strong>{formatPeso(detail.cash_advance_deductions)}</strong></article><article className="metric-card"><span>Prior Payroll Recovery</span><strong>{formatPeso(detail.payroll_recovery_deductions)}</strong></article><article className="metric-card"><span>Statutory Deductions</span><strong>{formatPeso(detail.statutory_employee_deductions)}</strong></article><article className="metric-card"><span>Net Pay</span><strong>{formatPeso(detail.net_pay)}</strong></article></section>
          {detail.status === 'PAID' && (detail.payment_splits?.length || detail.payment_account) ? <div className="form-alert info">Paid via <strong>{detail.payment_splits?.length ? detail.payment_splits.map((split) => `${split.funding_source?.name || 'Funding Source'} ${formatPeso(split.amount)}`).join(' + ') : detail.payment_account?.name}</strong>{detail.payment_splits?.length === 1 && detail.payment_splits[0]?.reference ? ` · ${detail.payment_splits[0].reference}` : (!detail.payment_splits?.length && detail.payment_reference ? ` · ${detail.payment_reference}` : '')}</div> : null}
          {detail.status === 'DRAFT' && (detail.approval_exceptions || []).length ? <section className="payroll-exception-panel">
            <strong>Pre-Approval Check</strong>
            {(detail.approval_exceptions || []).map((row) => <div key={row.code} className={`payroll-exception-row ${row.severity === 'BLOCKING' ? 'is-blocking' : 'is-warning'}`}><span>{row.severity === 'BLOCKING' ? 'Resolve' : 'Review'}</span><p>{row.message}</p></div>)}
          </section> : null}
          <div className="table-wrap"><table className="data-table"><thead><tr><th>Personnel</th><th>Basic</th><th>OT</th><th>Commission</th><th>Top-Up</th><th>Gross</th><th>Adjustments</th><th>Cash Advance</th><th>Prior Recovery</th><th>Statutory</th><th>Net Pay</th><th className="actions-column">Action</th></tr></thead><tbody>{(detail.lines || []).map((line) => <tr key={line.id}><td><strong>{line.personnel?.name}</strong><span className="table-subline">{line.personnel?.code}{line.personnel?.position ? ` · ${line.personnel.position}` : ''}</span></td><td>{formatPeso(line.hourly_basic_pay)}</td><td>{formatPeso(line.ot_pay)}</td><td>{formatPeso(line.commission_amount)}</td><td>{formatPeso(line.guaranteed_top_up)}</td><td>{formatPeso(line.gross_compensation)}</td><td>{formatPeso(line.adjustments_total)}</td><td><strong>{formatPeso(line.cash_advance_deductions)}</strong>{(line.cash_advance_options || []).map((option) => <span key={option.cash_advance_id} className="table-subline">{option.code || 'Cash Advance'} · {option.waived ? `Waived ${formatPeso(option.waived_amount || option.scheduled_amount)}` : `Scheduled ${formatPeso(option.deduction_amount || option.scheduled_amount)}`}</span>)}</td><td><strong>{formatPeso(line.payroll_recovery_deductions)}</strong>{(line.payroll_recoveries || []).map((recovery) => <span key={recovery.id || recovery.code} className="table-subline">{recovery.code || 'Recovery'}{recovery.source_label ? ` · ${recovery.source_label}` : ''}{recovery.original_payroll_code ? ` · from ${recovery.original_payroll_code}` : ''}</span>)}</td><td><strong>{formatPeso(line.statutory_employee_deductions)}</strong><span className="table-subline">SSS {formatPeso(line.sss_employee)} · PhilHealth {formatPeso(line.philhealth_employee)} · Pag-IBIG {formatPeso(line.pagibig_employee)}</span></td><td><strong>{formatPeso(line.net_pay)}</strong></td><td className="action-cell"><ActionMenu items={detail.status === 'DRAFT' && canManageCashAdvanceWaiver ? (line.cash_advance_options || []).map((option) => option.waived ? { label: `Restore ${option.code || 'Cash Advance'}`, onClick: () => requestCashAdvanceAction(line, option, 'RESTORE') } : { label: `Waive ${option.code || 'Cash Advance'}`, onClick: () => requestCashAdvanceAction(line, option, 'WAIVE') }) : []} /></td></tr>)}</tbody></table></div>
          {!(detail.lines || []).length ? <div className="empty-state">No eligible payroll sources were found for this period.</div> : null}
          {detail.status === 'DRAFT' ? <div className="modal-actions payroll-run-detail-actions"><button className="secondary-button" type="button" disabled={busy} onClick={() => executeRunAction('recalculate')}>Recalculate</button><button className="secondary-button" type="button" disabled={busy} onClick={() => requestRunAction('cancel')}>Cancel Draft</button><button className="primary-button" type="button" disabled={busy || blockingExceptions.length > 0} onClick={() => requestRunAction('approve')}>{recalculationRequired ? 'Recalculate Required' : 'Approve Payroll'}</button></div> : null}
          {detail.status === 'APPROVED' ? <div className="modal-actions payroll-run-detail-actions"><button className="primary-button" type="button" disabled={busy} onClick={() => requestRunAction('mark-paid')}>Mark Paid</button></div> : null}
        </div>
      </Modal> : null}

      {paymentForm ? <Modal wide title="Mark Payroll Paid" subtitle="Allocate the Net Pay across the actual business funding sources used. You may use up to 5 different sources." onClose={() => setPaymentForm(null)}>
        <form className="page-stack" onSubmit={submitPayrollPayment}>
          <div className="request-detail-grid payroll-payment-summary-head">
            <div><span>Payroll</span><strong>{detail?.code}</strong></div>
            <div><span>Net Pay</span><strong>{formatPeso(detail?.net_pay)}</strong></div>
            <div><span>Allocated</span><strong>{formatPeso(payrollFundingTotal)}</strong></div>
            <div><span>Balance</span><strong>{formatPeso(payrollFundingBalance)}</strong></div>
          </div>

          <section className="payroll-funding-section">
            <div className="expense-lines-heading">
              <div><h3>Payroll Funding Sources</h3><p>Select the actual sources used to release payroll. Petty Cash-funded amounts automatically appear in the Petty Cash ledger.</p></div>
              <button className="secondary-button" type="button" disabled={paymentForm.funding_splits.length >= 5} onClick={addPayrollFundingSplit}>+ Add Source</button>
            </div>
            <div className="payroll-funding-list">
              {paymentForm.funding_splits.map((split, index) => {
                const selectedElsewhere = new Set(paymentForm.funding_splits.filter((_, rowIndex) => rowIndex !== index).map((row) => String(row.funding_account_id)).filter(Boolean))
                return <div className="payroll-funding-row" key={index}>
                  <label className="form-field"><span>Source {index + 1}</span><SearchableSelect required value={split.funding_account_id} onChange={(e) => updatePayrollFundingSplit(index, 'funding_account_id', e.target.value)}><option value="">Select funding source</option>{(data?.funding_sources || []).map((row) => <option key={row.id} value={row.id} disabled={selectedElsewhere.has(String(row.id))}>{row.name}</option>)}</SearchableSelect></label>
                  <label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={split.amount} onChange={(e) => updatePayrollFundingSplit(index, 'amount', e.target.value)} /></label>
                  <label className="form-field"><span>Reference</span><input value={split.reference} onChange={(e) => updatePayrollFundingSplit(index, 'reference', e.target.value)} placeholder="Optional" /></label>
                  <button className="secondary-button compact-button payroll-funding-remove" type="button" disabled={paymentForm.funding_splits.length === 1} onClick={() => removePayrollFundingSplit(index)}>Remove</button>
                </div>
              })}
            </div>
          </section>

          <div className={`payroll-funding-balance ${payrollFundingBalanced ? 'is-balanced' : 'is-open'}`}><span>Remaining Balance</span><strong>{formatPeso(payrollFundingBalance)}</strong><small>{payrollFundingBalanced ? 'Fully allocated' : 'Funding sources must equal Net Pay.'}</small></div>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setPaymentForm(null)}>Cancel</button><button className="primary-button" disabled={busy || !payrollFundingBalanced || paymentForm.funding_splits.some((split) => !split.funding_account_id || Number(split.amount) <= 0)}>{busy ? 'Processing...' : 'Mark Paid'}</button></div>
        </form>
      </Modal> : null}

      {cashAdvanceAction ? <Modal title={cashAdvanceAction.mode === 'WAIVE' ? 'Waive Cash Advance Deduction' : 'Restore Cash Advance Deduction'} subtitle="Manager control for this Payroll Draft only. The cash advance balance itself is not forgiven." onClose={() => setCashAdvanceAction(null)}>
        <form className="page-stack" onSubmit={submitCashAdvanceAction}>
          <div className="request-detail-grid">
            <div><span>Personnel</span><strong>{cashAdvanceAction.line.personnel?.name}</strong></div>
            <div><span>Cash Advance</span><strong>{cashAdvanceAction.option.code || 'Cash Advance'}</strong></div>
            <div><span>Scheduled</span><strong>{formatPeso(cashAdvanceAction.option.scheduled_amount)}</strong></div>
            <div><span>Current Deduction</span><strong>{formatPeso(cashAdvanceAction.option.deduction_amount)}</strong></div>
          </div>
          <label className="form-field"><span>Reason</span><textarea required rows="3" value={cashAdvanceReason} onChange={(e) => setCashAdvanceReason(e.target.value)} placeholder={cashAdvanceAction.mode === 'WAIVE' ? 'Why should this deduction be skipped for this payroll run?' : 'Why should the deduction be restored?'} /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setCashAdvanceAction(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? 'Saving...' : cashAdvanceAction.mode === 'WAIVE' ? 'Waive This Run' : 'Restore Deduction'}</button></div>
        </form>
      </Modal> : null}

      {confirmation ? <ActionConfirmationModal
        title={confirmation.title}
        message={confirmation.message}
        details={confirmation.details}
        confirmLabel={confirmation.confirmLabel}
        danger={confirmation.danger}
        busy={busy}
        onCancel={() => setConfirmation(null)}
        onConfirm={confirmCurrentAction}
      /> : null}
    </div>
  )
}
