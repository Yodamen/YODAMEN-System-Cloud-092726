import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import SearchableSelect from '../../components/common/SearchableSelect'
import { formatPeso } from '../../utils/formatters'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import { useAuth } from '../../context/AuthContext'
import './cash-handling.css'

const localDateIso = (date = new Date()) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const today = () => localDateIso()
const currentMonth = () => today().slice(0, 7)
const monthStart = () => `${currentMonth()}-01`
const peso = (value) => formatPeso(Number(value || 0))
const dateLabel = (value) => value ? new Date(`${String(value).slice(0, 10)}T12:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—'
const changeMonth = (value, delta) => {
  const [year, month] = value.split('-').map(Number)
  const next = new Date(year, month - 1 + delta, 1)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}
const statusTone = (status) => {
  if (['DEPOSITED'].includes(status)) return 'success'
  if (['REMITTED', 'PARTIALLY_DEPOSITED'].includes(status)) return 'info'
  if (['PARTIAL'].includes(status)) return 'warning'
  if (['REVERSED', 'PENDING'].includes(status)) return 'danger'
  return 'neutral'
}
const handlingStatusLabel = (status) => ({
  NO_CASH: 'No Cash',
  PENDING: 'Pending',
  PARTIAL: 'Partial',
  REMITTED: 'Remitted',
  DEPOSITED: 'Deposited',
  PARTIALLY_DEPOSITED: 'Partial Deposit',
  REVERSED: 'Reversed',
}[status] || String(status || '').replaceAll('_', ' '))

export default function CashHandlingPage() {
  const { hasPermission } = useAuth()
  const [processTab, setProcessTab] = useState('REMIT')
  const [view, setView] = useState('LIST')
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '' })
  const [calendarMonth, setCalendarMonth] = useState(currentMonth())
  const [remitData, setRemitData] = useState({ rows: [], summary: {}, options: {} })
  const [depositData, setDepositData] = useState({ rows: [], summary: {}, options: {} })
  const [tipData, setTipData] = useState({ rows: [], summary: {}, options: {} })
  const [calendar, setCalendar] = useState(null)
  const [remitForm, setRemitForm] = useState(null)
  const [depositForm, setDepositForm] = useState(null)
  const [tipPayout, setTipPayout] = useState(null)
  const [daySummary, setDaySummary] = useState({})
  const [reversing, setReversing] = useState(null)
  const [reversalApproval, setReversalApproval] = useState(null)
  const [reversalReject, setReversalReject] = useState(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState('')

  const options = useMemo(() => ({
    branches: remitData.options?.branches?.length ? remitData.options.branches : (depositData.options?.branches || []),
    recipients: remitData.options?.recipients || [],
    source_accounts: depositData.options?.source_accounts || remitData.options?.source_accounts || [],
    bank_accounts: depositData.options?.bank_accounts || [],
    available_remittances: depositData.options?.available_remittances || [],
    tip_payout_accounts: tipData.options?.payout_accounts || [],
  }), [remitData.options, depositData.options])

  const loadLists = useCallback(async () => {
    setLoading(true)
    try {
      const params = { ...filters, branch_id: filters.branch_id || undefined }
      const [remitResponse, depositResponse, tipResponse] = await Promise.all([
        api.get('/v1/accounting/cash-remittances', { params }),
        api.get('/v1/accounting/cash-deposits', { params }),
        api.get('/v1/accounting/staff-tips', { params }),
      ])
      setRemitData(remitResponse.data)
      setDepositData(depositResponse.data)
      setTipData(tipResponse.data)
      setMessage('')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load cash handling records.'))
    } finally {
      setLoading(false)
    }
  }, [filters])

  const loadCalendar = useCallback(async () => {
    setLoading(true)
    try {
      const response = await api.get('/v1/accounting/cash-handling/calendar', { params: { month: calendarMonth, branch_id: filters.branch_id || undefined } })
      setCalendar(response.data)
      setMessage('')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load cash handling calendar.'))
    } finally {
      setLoading(false)
    }
  }, [calendarMonth, filters.branch_id])

  useEffect(() => { loadLists() }, [])
  useEffect(() => { if (view === 'CALENDAR') loadCalendar() }, [view, calendarMonth, filters.branch_id])

  const refresh = () => view === 'CALENDAR' ? loadCalendar() : loadLists()

  const openRemit = () => {
    const branchId = filters.branch_id || options.branches?.[0]?.id || ''
    const recipientId = options.recipients?.[0]?.id || ''
    setMessage('')
    setDaySummary({})
    setRemitForm({ branch_id: String(branchId), business_date: today(), remittance_date: today(), received_by_user_id: String(recipientId), amount: '', reference: '', notes: '', supporting_files: [] })
  }

  const openDeposit = () => {
    const branchId = filters.branch_id || options.branches?.[0]?.id || ''
    const sourceId = options.source_accounts?.[0]?.id || ''
    const bankId = options.bank_accounts?.[0]?.id || ''
    setMessage('')
    setDaySummary({})
    setDepositForm({ deposit_source: 'DIRECT', branch_id: String(branchId), business_date: today(), deposit_date: today(), source_cash_account_id: String(sourceId), cash_remittance_id: '', bank_account_id: String(bankId), amount: '', reference: '', notes: '', supporting_files: [] })
  }

  const loadRemitSummary = useCallback(async (draft) => {
    if (!draft?.branch_id || !draft?.business_date) { setDaySummary({}); return }
    try {
      const response = await api.get('/v1/accounting/cash-remittances/day-summary', { params: { branch_id: Number(draft.branch_id), business_date: draft.business_date } })
      setDaySummary(response.data.summary || {})
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to calculate cash available for remittance.'))
    }
  }, [])

  const loadDepositSummary = useCallback(async (draft) => {
    if (!draft) return
    try {
      if (draft.deposit_source === 'REMITTANCE') {
        const selected = options.available_remittances.find((row) => String(row.id) === String(draft.cash_remittance_id))
        if (!selected) { setDaySummary({}); return }
        const response = await api.get('/v1/accounting/cash-deposits/day-summary', { params: {
          branch_id: Number(selected.branch_id), business_date: selected.business_date, deposit_source: 'REMITTANCE', cash_remittance_id: Number(selected.id),
        } })
        setDaySummary(response.data.summary || {})
        return
      }
      if (!draft.branch_id || !draft.business_date || !draft.source_cash_account_id) { setDaySummary({}); return }
      const response = await api.get('/v1/accounting/cash-deposits/day-summary', { params: {
        branch_id: Number(draft.branch_id), business_date: draft.business_date, source_cash_account_id: Number(draft.source_cash_account_id), deposit_source: 'DIRECT',
      } })
      setDaySummary(response.data.summary || {})
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to calculate cash available for deposit.'))
    }
  }, [options.available_remittances])

  useEffect(() => { if (remitForm) loadRemitSummary(remitForm) }, [remitForm?.branch_id, remitForm?.business_date])
  useEffect(() => { if (depositForm) loadDepositSummary(depositForm) }, [depositForm?.deposit_source, depositForm?.branch_id, depositForm?.business_date, depositForm?.source_cash_account_id, depositForm?.cash_remittance_id])

  const saveRemit = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(remitForm.supporting_files || [], 'cash-remittances', 'CASH_REMITTANCE_SUPPORT')
      const { supporting_files, ...remitPayload } = remitForm
      await api.post('/v1/accounting/cash-remittances', {
        ...remitPayload,
        attachment_tokens: attachmentTokens,
        branch_id: Number(remitForm.branch_id),
        received_by_user_id: Number(remitForm.received_by_user_id),
        amount: Number(remitForm.amount),
      })
      setRemitForm(null)
      await loadLists()
      if (view === 'CALENDAR') await loadCalendar()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to post cash remittance.'))
    } finally { setSaving(false) }
  }

  const saveDeposit = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const selectedRemittance = depositForm.deposit_source === 'REMITTANCE'
        ? options.available_remittances.find((row) => String(row.id) === String(depositForm.cash_remittance_id))
        : null
      const attachmentTokens = await uploadSupportingDocuments(depositForm.supporting_files || [], 'cash-deposits', 'DEPOSIT_PROOF')
      if (!attachmentTokens.length) throw new Error('Deposit proof is required.')
      const { supporting_files, ...depositPayload } = depositForm
      await api.post('/v1/accounting/cash-deposits', {
        ...depositPayload,
        attachment_tokens: attachmentTokens,
        branch_id: Number(selectedRemittance?.branch_id || depositForm.branch_id),
        business_date: selectedRemittance?.business_date || depositForm.business_date,
        source_cash_account_id: depositForm.deposit_source === 'DIRECT' ? Number(depositForm.source_cash_account_id) : null,
        cash_remittance_id: depositForm.deposit_source === 'REMITTANCE' ? Number(depositForm.cash_remittance_id) : null,
        bank_account_id: Number(depositForm.bank_account_id),
        amount: Number(depositForm.amount),
      })
      setDepositForm(null)
      await loadLists()
      if (view === 'CALENDAR') await loadCalendar()
    } catch (error) {
      setMessage(getApiErrorMessage(error, error?.message || 'Unable to post bank deposit.'))
    } finally { setSaving(false) }
  }

  const saveTipPayout = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      await api.post(`/v1/accounting/staff-tips/${tipPayout.id}/payout`, {
        payout_account_id: Number(tipPayout.payout_account_id),
        payout_date: tipPayout.payout_date,
        payout_reference: tipPayout.payout_reference || null,
      })
      setTipPayout(null)
      await loadLists()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to release staff tip.'))
    } finally { setSaving(false) }
  }

  const openTipPayout = (tip) => {
    setMessage('')
    setTipPayout({
      ...tip,
      payout_account_id: String(options.tip_payout_accounts?.[0]?.id || ''),
      payout_date: today(),
      payout_reference: '',
    })
  }

  const reverse = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const endpoint = reversing.kind === 'REMIT' ? `/v1/accounting/cash-remittances/${reversing.id}/reverse` : `/v1/accounting/cash-deposits/${reversing.id}/reverse`
      await api.post(endpoint, { reason_reference_id: Number(reversing.reason_reference_id), reason: reversing.reason })
      setReversing(null)
      await loadLists()
      if (view === 'CALENDAR') await loadCalendar()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit cash handling reversal request.'))
    } finally { setSaving(false) }
  }


  const approveReversal = async () => {
    if (!reversalApproval) return
    setSaving(true); setMessage('')
    try {
      const base = reversalApproval.kind === 'REMIT' ? '/v1/accounting/cash-remittances' : '/v1/accounting/cash-deposits'
      await api.post(`${base}/reversal-requests/${reversalApproval.request.id}/approve`)
      setReversalApproval(null)
      await loadLists()
      if (view === 'CALENDAR') await loadCalendar()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve cash handling reversal.'))
    } finally { setSaving(false) }
  }

  const rejectReversal = async (event) => {
    event.preventDefault()
    if (!reversalReject) return
    setSaving(true); setMessage('')
    try {
      const base = reversalReject.kind === 'REMIT' ? '/v1/accounting/cash-remittances' : '/v1/accounting/cash-deposits'
      await api.post(`${base}/reversal-requests/${reversalReject.request.id}/reject`, { review_reason_reference_id: Number(reversalReject.review_reason_reference_id), notes: reversalReject.notes })
      setReversalReject(null)
      await loadLists()
      if (view === 'CALENDAR') await loadCalendar()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to reject cash handling reversal.'))
    } finally { setSaving(false) }
  }

  const canApproveReversal = hasPermission('cash-deposits', 'APPROVE_REVERSAL')

  const chooseDepositSource = (value) => {
    if (value === 'REMITTANCE') {
      const remittance = options.available_remittances?.[0]
      setDepositForm((current) => ({ ...current, deposit_source: value, cash_remittance_id: remittance ? String(remittance.id) : '', branch_id: remittance ? String(remittance.branch_id) : current.branch_id, business_date: remittance?.business_date || current.business_date, amount: '' }))
    } else {
      setDepositForm((current) => ({ ...current, deposit_source: 'DIRECT', cash_remittance_id: '', amount: '' }))
    }
  }

  const chooseRemittance = (value) => {
    const remittance = options.available_remittances.find((row) => String(row.id) === String(value))
    setDepositForm((current) => ({ ...current, cash_remittance_id: value, branch_id: remittance ? String(remittance.branch_id) : current.branch_id, business_date: remittance?.business_date || current.business_date, amount: '' }))
  }

  const firstWeekday = calendar?.days?.length ? new Date(`${calendar.days[0].business_date}T12:00:00`).getDay() : 0
  const actionLabel = processTab === 'REMIT' ? 'Record Remittance' : processTab === 'DEPOSIT' ? 'Record Deposit' : ''

  const tipsPanel = <>
    <section className="content-card"><div className="cash-handling-filters">
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((current) => ({ ...current, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((current) => ({ ...current, to: e.target.value }))} /></label>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((current) => ({ ...current, branch_id: e.target.value }))}><option value="">All Branches</option>{options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      <button className="secondary-button cash-handling-apply" type="button" onClick={loadLists}>Apply</button>
    </div></section>

    <section className="content-card cash-handling-tips-card">
      <div className="cash-handling-tips-heading">
        <div><strong>Electronic Tips Payable</strong><span>Only tips received through business bank, e-wallet, or card/POS payment methods are tracked.</span></div>
        <div className="cash-handling-tip-summary"><span>Pending</span><strong>{peso(tipData.summary?.pending_amount)}</strong></div>
      </div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Tip</th><th>Personnel</th><th>Received Via</th><th>Amount</th><th>Status</th><th>Payout</th></tr></thead><tbody>
        {(tipData.rows || []).map((tip) => <tr key={tip.id}><td><strong>{tip.code}</strong><span className="table-subline">{tip.created_at ? dateLabel(tip.created_at) : '—'}</span></td><td>{tip.personnel?.name || '—'}</td><td><strong>{tip.received_method || '—'}</strong><span className="table-subline">{tip.received_reference || 'No reference'}</span></td><td><strong>{peso(tip.amount)}</strong></td><td><span className={`status-pill ${tip.status === 'PAID' ? 'success' : 'warning'}`}>{tip.status}</span></td><td>{tip.status === 'PENDING' ? <button className="secondary-button compact-button" type="button" onClick={() => openTipPayout(tip)}>Release</button> : <><strong>{tip.payout_account?.name || tip.payout_method || 'Paid'}</strong>{tip.payout_reference ? <span className="table-subline">{tip.payout_reference}</span> : null}</>}</td></tr>)}
        {!loading && !(tipData.rows || []).length ? <tr><td colSpan="6"><div className="empty-state">No tracked electronic tips found for this filter.</div></td></tr> : null}
      </tbody></table></div>
    </section>
  </>

  return <div className="page-stack cash-handling-page">
    <section className="page-heading live-page-heading">
      <div><h1 className="live-page-title">Cash Handling <PageRefreshButton label="Refresh Cash Handling" onRefresh={refresh} /></h1><p>Track physical cash from customer collection through manager remittance and final bank deposit.</p></div>
      {actionLabel ? <button className="primary-button" type="button" onClick={processTab === 'REMIT' ? openRemit : openDeposit}>{actionLabel}</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <div className="booking-tabs cash-handling-process-tabs" role="tablist" aria-label="Cash handling process">
      <button type="button" className={processTab === 'REMIT' ? 'active' : ''} onClick={() => setProcessTab('REMIT')}>Remit</button>
      <button type="button" className={processTab === 'DEPOSIT' ? 'active' : ''} onClick={() => setProcessTab('DEPOSIT')}>Deposit</button>
      <button type="button" className={processTab === 'TIPS' ? 'active' : ''} onClick={() => setProcessTab('TIPS')}>Tips</button>
    </div>

    {processTab !== 'TIPS' ? <section className="cash-handling-view-row">
      <div className="segmented-control" role="tablist" aria-label="Cash handling view">
        <button type="button" className={view === 'LIST' ? 'active' : ''} onClick={() => setView('LIST')}>List</button>
        <button type="button" className={view === 'CALENDAR' ? 'active' : ''} onClick={() => setView('CALENDAR')}>Calendar</button>
      </div>
    </section> : null}

    {processTab === 'TIPS' ? tipsPanel : view === 'LIST' ? <>
      {processTab === 'REMIT' ? <section className="metric-grid cash-handling-metrics">
        <article className="metric-card"><span>Remitted in Filter</span><strong>{peso(remitData.summary?.remitted)}</strong></article>
        <article className="metric-card"><span>Deposited from Remittance</span><strong>{peso(remitData.summary?.deposited_from_remittance)}</strong></article>
        <article className="metric-card"><span>Still with Manager</span><strong>{peso(remitData.summary?.still_with_manager)}</strong></article>
        <article className="metric-card"><span>Posted Remittances</span><strong>{remitData.summary?.transactions || 0}</strong></article>
      </section> : <section className="metric-grid cash-handling-metrics">
        <article className="metric-card"><span>Deposited in Filter</span><strong>{peso(depositData.summary?.deposited)}</strong></article>
        <article className="metric-card"><span>Direct Deposits</span><strong>{peso(depositData.summary?.direct_deposited)}</strong></article>
        <article className="metric-card"><span>From Remittances</span><strong>{peso(depositData.summary?.remitted_deposited)}</strong></article>
        <article className="metric-card"><span>Posted Deposits</span><strong>{depositData.summary?.transactions || 0}</strong></article>
      </section>}

      <section className="content-card"><div className="cash-handling-filters">
        <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((current) => ({ ...current, from: e.target.value }))} /></label>
        <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((current) => ({ ...current, to: e.target.value }))} /></label>
        <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((current) => ({ ...current, branch_id: e.target.value }))}><option value="">All Branches</option>{options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
        <button className="secondary-button cash-handling-apply" type="button" onClick={loadLists}>Apply</button>
      </div></section>

      {processTab === 'REMIT' ? <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Remittance</th><th>Business Date</th><th>Remit Date</th><th>Branch</th><th>Remitted By</th><th>Received By</th><th>Amount</th><th>Deposited</th><th>Still Held</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {(remitData.rows || []).map((row) => {
          const pending = row.pending_reversal
          return <tr key={row.id}><td><strong>{row.code}</strong><span className="table-subline">{row.reference || 'No reference'}</span></td><td>{dateLabel(row.business_date)}</td><td>{dateLabel(row.remittance_date)}</td><td>{row.branch?.name || '—'}</td><td>{row.remitted_by || '—'}</td><td>{row.received_by || '—'}</td><td><strong>{peso(row.amount)}</strong></td><td>{peso(row.deposited_amount)}</td><td>{peso(row.remaining_amount)}</td><td>{pending ? <span className="status-pill warning">Pending Approval</span> : <span className={`status-pill ${statusTone(row.handling_status)}`}>{handlingStatusLabel(row.handling_status)}</span>}</td><td className="action-cell"><ActionMenu items={pending ? (canApproveReversal ? [
            { label: 'Approve Reversal', onClick: () => setReversalApproval({ kind: 'REMIT', request: pending }) },
            { label: 'Reject Reversal', onClick: () => setReversalReject({ kind: 'REMIT', request: pending, review_reason_reference_id: '', notes: '' }) },
          ] : []) : [row.status === 'POSTED' && Number(row.deposited_amount || 0) <= 0 && hasPermission('cash-deposits', 'REVERSE') ? { label: 'Request Reversal', onClick: () => setReversing({ ...row, kind: 'REMIT', reason_reference_id: '', reason: '' }) } : null]} /></td></tr>
        })}
        {!loading && !(remitData.rows || []).length ? <tr><td colSpan="11"><div className="empty-state">No cash remittances found for this filter.</div></td></tr> : null}
      </tbody></table></div></section> : <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Deposit</th><th>Business Date</th><th>Deposit Date</th><th>Branch</th><th>Deposited By</th><th>Source</th><th>To Bank</th><th>Amount</th><th>Reference</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {(depositData.rows || []).map((row) => {
          const pending = row.pending_reversal
          return <tr key={row.id}><td><strong>{row.code}</strong></td><td>{dateLabel(row.business_date)}</td><td>{dateLabel(row.deposit_date)}</td><td>{row.branch?.name || '—'}</td><td>{row.deposited_by || '—'}</td><td><strong>{row.deposit_source === 'REMITTANCE' ? 'Remitted Cash' : 'Direct Cash'}</strong><span className="table-subline">{row.cash_remittance ? `${row.cash_remittance.code} · ${row.cash_remittance.received_by || ''}` : (row.source_account ? `${row.source_account.code} · ${row.source_account.name}` : '—')}</span></td><td>{row.bank_account ? `${row.bank_account.code} · ${row.bank_account.name}` : '—'}</td><td><strong>{peso(row.amount)}</strong></td><td>{row.reference || '—'}</td><td>{pending ? <span className="status-pill warning">Pending Approval</span> : <span className={`status-pill ${row.status === 'POSTED' ? 'success' : 'danger'}`}>{row.status}</span>}</td><td className="action-cell"><ActionMenu items={pending ? (canApproveReversal ? [
            { label: 'Approve Reversal', onClick: () => setReversalApproval({ kind: 'DEPOSIT', request: pending }) },
            { label: 'Reject Reversal', onClick: () => setReversalReject({ kind: 'DEPOSIT', request: pending, review_reason_reference_id: '', notes: '' }) },
          ] : []) : [row.status === 'POSTED' && hasPermission('cash-deposits', 'REVERSE') ? { label: 'Request Reversal', onClick: () => setReversing({ ...row, kind: 'DEPOSIT', reason_reference_id: '', reason: '' }) } : null]} /></td></tr>
        })}
        {!loading && !(depositData.rows || []).length ? <tr><td colSpan="11"><div className="empty-state">No bank deposits found for this filter.</div></td></tr> : null}
      </tbody></table></div></section>}

    </> : <>
      <section className="content-card"><div className="cash-handling-calendar-controls">
        <button className="secondary-button compact-button" type="button" onClick={() => setCalendarMonth((value) => changeMonth(value, -1))}>‹</button>
        <label className="form-field"><span>Month</span><input type="month" value={calendarMonth} onChange={(e) => setCalendarMonth(e.target.value)} /></label>
        <button className="secondary-button compact-button" type="button" onClick={() => setCalendarMonth((value) => changeMonth(value, 1))}>›</button>
        <label className="form-field cash-handling-calendar-branch"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((current) => ({ ...current, branch_id: e.target.value }))}><option value="">All Branches</option>{options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      </div></section>

      <section className="metric-grid cash-handling-metrics">
        <article className="metric-card"><span>Cash Sales</span><strong>{peso(calendar?.summary?.cash_sales)}</strong></article>
        <article className="metric-card"><span>Still with Manager</span><strong>{peso(calendar?.summary?.remitted_open)}</strong></article>
        <article className="metric-card"><span>Deposited</span><strong>{peso(calendar?.summary?.deposited)}</strong></article>
        <article className="metric-card"><span>Remaining to Bank</span><strong>{peso(calendar?.summary?.remaining_to_bank)}</strong></article>
      </section>

      <section className="content-card cash-handling-calendar-card">
        <div className="cash-handling-calendar-title"><strong>{calendar?.month_label || calendarMonth}</strong><div className="cash-handling-calendar-legend"><span><i className="cash-status-dot deposited" />Deposited</span><span><i className="cash-status-dot remitted" />Remitted</span><span><i className="cash-status-dot partial" />Partial</span><span><i className="cash-status-dot pending" />Pending</span><span><i className="cash-status-dot no-cash" />No Cash</span></div></div>
        {loading ? <div className="empty-state">Loading calendar...</div> : <><div className="cash-handling-calendar-weekdays">{['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => <span key={day}>{day}</span>)}</div><div className="cash-handling-calendar-grid">{Array.from({ length: firstWeekday }).map((_, index) => <div className="cash-handling-calendar-day blank" key={`blank-${index}`} />)}{(calendar?.days || []).map((day) => <button type="button" key={day.business_date} className={`cash-handling-calendar-day ${day.status.toLowerCase().replaceAll('_', '-')}`} onClick={() => { setFilters((current) => ({ ...current, from: day.business_date, to: day.business_date })); setView('LIST') }}><div className="cash-calendar-day-head"><strong>{Number(day.business_date.slice(-2))}</strong><span>{handlingStatusLabel(day.status)}</span></div><div className="cash-calendar-day-values"><span>Cash {peso(day.expected_cash_sales)}</span>{day.remitted_open > 0 ? <span>With Manager {peso(day.remitted_open)}</span> : null}{day.deposited_total > 0 ? <span>Banked {peso(day.deposited_total)}</span> : null}{day.remaining_to_bank > 0 ? <span>To Bank {peso(day.remaining_to_bank)}</span> : null}</div></button>)}</div></>}
      </section>
    </>}

    {remitForm ? <Modal wide title="Record Cash Remittance" subtitle="Record the physical turnover of cash sales to the manager. This moves custody from Cash on Hand to Cash with Manager / Undeposited Cash without changing revenue." onClose={() => setRemitForm(null)}>
      <form onSubmit={saveRemit}><div className="cash-handling-due-strip"><div><span>Recorded Cash Sales</span><strong>{peso(daySummary.expected_cash_sales)}</strong></div><div><span>Already Handled</span><strong>{peso(daySummary.handled_total)}</strong></div><div><span>Remaining to Remit</span><strong>{peso(daySummary.remaining_to_remit)}</strong></div></div>
        <div className="form-grid">
          <label className="form-field"><span>Branch</span><SearchableSelect required value={remitForm.branch_id} onChange={(e) => setRemitForm((current) => ({ ...current, branch_id: e.target.value }))}>{options.branches.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Business Date</span><input required type="date" value={remitForm.business_date} onChange={(e) => setRemitForm((current) => ({ ...current, business_date: e.target.value }))} /></label>
          <label className="form-field"><span>Remittance Date</span><input required type="date" value={remitForm.remittance_date} onChange={(e) => setRemitForm((current) => ({ ...current, remittance_date: e.target.value }))} /></label>
          <label className="form-field"><span>Received By Manager</span><SearchableSelect required value={remitForm.received_by_user_id} onChange={(e) => setRemitForm((current) => ({ ...current, received_by_user_id: e.target.value }))}>{options.recipients.map((row) => <option key={row.id} value={row.id}>{row.name}{row.role ? ` · ${row.role}` : ''}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Remittance Amount</span><input required type="number" min="0.01" step="0.01" max={Number(daySummary.remaining_to_remit || 0) || undefined} value={remitForm.amount} onChange={(e) => setRemitForm((current) => ({ ...current, amount: e.target.value }))} /></label>
          <label className="form-field"><span>Reference / Acknowledgment</span><input value={remitForm.reference} onChange={(e) => setRemitForm((current) => ({ ...current, reference: e.target.value }))} /></label>
          <label className="form-field cash-handling-span-all"><span>Notes</span><textarea rows="3" value={remitForm.notes} onChange={(e) => setRemitForm((current) => ({ ...current, notes: e.target.value }))} /></label>
          <div className="cash-handling-span-all"><SupportingDocumentField files={remitForm.supporting_files || []} onChange={(files) => setRemitForm((current) => ({ ...current, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach a cash turnover acknowledgment, remittance sheet or equivalent proof." /></div>
        </div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setRemitForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving || Number(remitForm.amount || 0) <= 0}>{saving ? 'Posting...' : 'Post Remittance'}</button></div>
      </form>
    </Modal> : null}

    {depositForm ? <Modal wide title="Record Bank Deposit" subtitle="Deposit cash directly from Cash on Hand or bank a previously remitted amount held by the manager. The entry transfers cash only and does not recognize revenue again." onClose={() => setDepositForm(null)}>
      <form onSubmit={saveDeposit}><div className="cash-handling-due-strip"><div><span>{depositForm.deposit_source === 'REMITTANCE' ? 'Remittance Amount' : 'Recorded Cash Sales'}</span><strong>{peso(depositForm.deposit_source === 'REMITTANCE' ? daySummary.remittance_amount : daySummary.expected_cash_sales)}</strong></div><div><span>Already Banked</span><strong>{peso(daySummary.deposited)}</strong></div><div><span>Available to Deposit</span><strong>{peso(daySummary.remaining_to_deposit)}</strong></div></div>
        <div className="form-grid">
          <label className="form-field"><span>Deposit Source</span><SearchableSelect required value={depositForm.deposit_source} onChange={(e) => chooseDepositSource(e.target.value)}><option value="DIRECT">Cash on Hand · Direct to Bank</option><option value="REMITTANCE">Remitted Cash · Manager to Bank</option></SearchableSelect></label>
          {depositForm.deposit_source === 'REMITTANCE' ? <label className="form-field"><span>Cash Remittance</span><SearchableSelect required value={depositForm.cash_remittance_id} onChange={(e) => chooseRemittance(e.target.value)}><option value="">Select remittance</option>{options.available_remittances.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.business_date} · {row.received_by || 'Manager'} · {peso(row.remaining_amount)} remaining</option>)}</SearchableSelect></label> : <>
            <label className="form-field"><span>Branch</span><SearchableSelect required value={depositForm.branch_id} onChange={(e) => setDepositForm((current) => ({ ...current, branch_id: e.target.value }))}>{options.branches.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
            <label className="form-field"><span>Business Date</span><input required type="date" value={depositForm.business_date} onChange={(e) => setDepositForm((current) => ({ ...current, business_date: e.target.value }))} /></label>
          </>}
          <label className="form-field"><span>Deposit Date</span><input required type="date" value={depositForm.deposit_date} onChange={(e) => setDepositForm((current) => ({ ...current, deposit_date: e.target.value }))} /></label>
          <label className="form-field"><span>Deposit To Bank / E-Wallet</span><SearchableSelect required value={depositForm.bank_account_id} onChange={(e) => setDepositForm((current) => ({ ...current, bank_account_id: e.target.value }))}>{options.bank_accounts.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Deposit Amount</span><input required type="number" min="0.01" step="0.01" max={Number(daySummary.remaining_to_deposit || 0) || undefined} value={depositForm.amount} onChange={(e) => setDepositForm((current) => ({ ...current, amount: e.target.value }))} /></label>
          <label className="form-field"><span>Bank Reference / Deposit Slip</span><input value={depositForm.reference} onChange={(e) => setDepositForm((current) => ({ ...current, reference: e.target.value }))} /></label>
          <label className="form-field cash-handling-span-all"><span>Notes</span><textarea rows="3" value={depositForm.notes} onChange={(e) => setDepositForm((current) => ({ ...current, notes: e.target.value }))} /></label>
          <div className="cash-handling-span-all"><SupportingDocumentField required files={depositForm.supporting_files || []} onChange={(files) => setDepositForm((current) => ({ ...current, supporting_files: files }))} label="Deposit Proof" hint="Required before confirming the deposit. Attach the bank deposit slip, transfer confirmation or e-wallet proof." /></div>
        </div>{depositForm.deposit_source === 'REMITTANCE' && !options.available_remittances.length ? <div className="form-alert warning">There is no remitted cash currently available for bank deposit.</div> : null}<div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setDepositForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving || Number(depositForm.amount || 0) <= 0 || (depositForm.deposit_source === 'REMITTANCE' && !depositForm.cash_remittance_id)}>{saving ? 'Posting...' : 'Post Deposit'}</button></div>
      </form>
    </Modal> : null}

    {tipPayout ? <Modal title={`Release ${tipPayout.code}`} subtitle={`${tipPayout.personnel?.name || 'Staff'} · ${peso(tipPayout.amount)}. Tip payout may come from Petty Cash or an approved bank/e-wallet Operating Fund Source. Cash on Hand is not allowed.`} onClose={() => setTipPayout(null)}>
      <form onSubmit={saveTipPayout}><div className="form-grid">
        <label className="form-field"><span>Payout Source</span><SearchableSelect required value={tipPayout.payout_account_id} onChange={(e) => setTipPayout((current) => ({ ...current, payout_account_id: e.target.value }))}><option value="">Select source</option>{options.tip_payout_accounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Payout Date</span><input required type="date" value={tipPayout.payout_date} onChange={(e) => setTipPayout((current) => ({ ...current, payout_date: e.target.value }))} /></label>
        <label className="form-field cash-handling-span-all"><span>Reference No.</span><input value={tipPayout.payout_reference} onChange={(e) => setTipPayout((current) => ({ ...current, payout_reference: e.target.value }))} placeholder="Optional for Petty Cash; recommended for bank/e-wallet" /></label>
      </div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setTipPayout(null)}>Cancel</button><button className="primary-button" disabled={saving || !tipPayout.payout_account_id} type="submit">{saving ? 'Releasing...' : 'Release Tip'}</button></div></form>
    </Modal> : null}

    {reversing ? <Modal title={`Request Reversal · ${reversing.code}`} subtitle="The request must be approved before custody or bank balances are changed." onClose={() => setReversing(null)}><form onSubmit={reverse}><WorkflowReasonSelect context={reversing.kind === 'REMIT' ? 'CASH_REMITTANCE_REVERSAL' : 'CASH_DEPOSIT_REVERSAL'} value={reversing.reason_reference_id} onChange={(e) => setReversing((current) => ({ ...current, reason_reference_id: e.target.value }))}/><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversing.reason} onChange={(e) => setReversing((current) => ({ ...current, reason: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversing(null)}>Cancel</button><button className="danger-button" disabled={saving} type="submit">Submit for Approval</button></div></form></Modal> : null}
    {reversalApproval ? <ActionConfirmationModal title="Approve Cash Handling Reversal" message="Approve this reversal request? The counter-entry will post immediately after approval." details={[{ label: 'Request', value: reversalApproval.request.code }, { label: 'Reason', value: reversalApproval.request.reason }]} confirmLabel="Approve Reversal" danger busy={saving} onCancel={() => setReversalApproval(null)} onConfirm={approveReversal} /> : null}
    {reversalReject ? <Modal title="Reject Reversal Request" subtitle={reversalReject.request.code} onClose={() => setReversalReject(null)}><form onSubmit={rejectReversal}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={reversalReject.review_reason_reference_id} onChange={(e) => setReversalReject((current) => ({ ...current, review_reason_reference_id: e.target.value }))}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={reversalReject.notes} onChange={(e) => setReversalReject((current) => ({ ...current, notes: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversalReject(null)}>Cancel</button><button className="danger-button" disabled={saving} type="submit">Reject Request</button></div></form></Modal> : null}
  </div>
}
