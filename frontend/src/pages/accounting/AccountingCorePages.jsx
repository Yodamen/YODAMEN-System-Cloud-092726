import SearchableSelect from '../../components/common/SearchableSelect'
import ActionMenu from '../../components/common/ActionMenu'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import { useAuth } from '../../context/AuthContext'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import '../expense/accounting-expense.css'

const localDateIso = (date = new Date()) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const today = () => localDateIso()
const monthStart = () => `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-01`
const peso = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
const dateLabel = (value) => value ? new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—'

function Heading({ title, subtitle, onRefresh, action }) {
  return <section className="page-heading live-page-heading"><div><h1 className="live-page-title">{title} <PageRefreshButton label={`Refresh ${title}`} onRefresh={onRefresh} /></h1><p>{subtitle}</p></div>{action || null}</section>
}

function Filters({ filters, setFilters, options, onApply, account = false, hideFrom = false }) {
  return <section className="content-card"><div className="accounting-filter-grid">
    {!hideFrom ? <label className="form-field"><span>From</span><input type="date" value={filters.from || ''} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label> : null}
    <label className="form-field"><span>To</span><input type="date" value={filters.to || ''} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
    <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id || ''} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All Branches</option>{(options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
    {account ? <label className="form-field"><span>Account</span><SearchableSelect value={filters.account_id || ''} onChange={(e) => setFilters((c) => ({ ...c, account_id: e.target.value }))}><option value="">All Accounts</option>{(options?.accounts || []).map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</SearchableSelect></label> : null}
    <div className="accounting-filter-actions"><button className="secondary-button" type="button" onClick={onApply}>Apply</button></div>
  </div></section>
}

function BalanceSummary({ summary }) {
  const debit = Number(summary?.debit || 0)
  const credit = Number(summary?.credit || 0)
  const balanced = Math.abs(debit - credit) < 0.01
  return <section className="accounting-balance-strip"><div><span>Total Debit</span><strong>{peso(debit)}</strong></div><div><span>Total Credit</span><strong>{peso(credit)}</strong></div><div><span>Status</span><strong className={balanced ? 'balance-ok' : 'balance-error'}>{balanced ? 'Balanced' : 'Out of Balance'}</strong></div></section>
}

export function GeneralLedgerPage() {
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '', account_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const [error, setError] = useState('')
  const load = useCallback(async () => { try { const response = await api.get('/v1/accounting/general-ledger', { params: { ...filters, branch_id: filters.branch_id || undefined, account_id: filters.account_id || undefined } }); setData(response.data) } catch (e) { setError(getApiErrorMessage(e)) } }, [filters])
  useEffect(() => { load() }, [])
  return <div className="page-stack accounting-page"><Heading title="General Ledger" subtitle="Posted, source-linked accounting entries with full debit and credit traceability." onRefresh={load} />{error ? <div className="form-alert error">{error}</div> : null}<div className="accounting-controls-row"><Filters {...{ filters, setFilters, options: data.options, onApply: load }} account /><BalanceSummary summary={data.summary} /></div><section className="content-card accounting-table-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Journal</th><th>Account</th><th>Reference / Source</th><th>Memo</th><th>Debit</th><th>Credit</th></tr></thead><tbody>{data.rows.map((row, index) => <tr key={`${row.journal_code}-${index}`}><td>{dateLabel(row.posting_date)}</td><td><strong>{row.journal_code}</strong></td><td>{row.account_code} · {row.account_name}</td><td>{row.reference || '—'}<span className="table-subline">{row.source_type} · {row.source_event}</span></td><td>{row.memo || row.description || '—'}</td><td>{Number(row.debit) > 0 ? peso(row.debit) : '—'}</td><td>{Number(row.credit) > 0 ? peso(row.credit) : '—'}</td></tr>)}</tbody></table></div></section></div>
}

export function TrialBalancePage() {
  const [filters, setFilters] = useState({ to: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const load = useCallback(async () => { const response = await api.get('/v1/accounting/trial-balance', { params: { to: filters.to, branch_id: filters.branch_id || undefined } }); setData(response.data) }, [filters])
  useEffect(() => { load() }, [])
  return <div className="page-stack accounting-page"><Heading title="Trial Balance" subtitle="Account balances from posted journals as of the selected date." onRefresh={load} /><div className="accounting-controls-row"><Filters {...{ filters, setFilters, options: data.options, onApply: load }} hideFrom /><BalanceSummary summary={data.summary} /></div><section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Account</th><th>Type</th><th>Debit</th><th>Credit</th></tr></thead><tbody>{data.rows.map((row) => <tr key={row.account_id}><td><strong>{row.code}</strong> · {row.name}</td><td>{row.account_type.replaceAll('_', ' ')}</td><td>{row.debit ? peso(row.debit) : '—'}</td><td>{row.credit ? peso(row.credit) : '—'}</td></tr>)}</tbody></table></div></section></div>
}

export function CashManagementPage() {
  const { hasPermission } = useAuth()
  const [filters, setFilters] = useState({ to: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const [funding, setFunding] = useState({ rows: [], options: {} })
  const [fundingForm, setFundingForm] = useState(null)
  const [reversingFunding, setReversingFunding] = useState(null)
  const [fundingReversalApproval, setFundingReversalApproval] = useState(null)
  const [fundingReversalReject, setFundingReversalReject] = useState(null)
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    try {
      const [cashResponse, fundingResponse] = await Promise.all([
        api.get('/v1/accounting/cash-management', { params: { to: filters.to, branch_id: filters.branch_id || undefined } }),
        api.get('/v1/accounting/business-funding'),
      ])
      setData(cashResponse.data)
      setFunding(fundingResponse.data)
      setMessage('')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load cash management.'))
    }
  }, [filters])
  useEffect(() => { load() }, [])

  const openFunding = () => {
    setFundingForm({
      branch_id: filters.branch_id || funding.options?.branches?.[0]?.id || '',
      funding_date: today(),
      funding_type: 'CAPITAL_CONTRIBUTION',
      cash_account_id: funding.options?.cash_accounts?.[0]?.id || '',
      amount: '', reference: '', notes: '', supporting_files: [],
    })
  }

  const saveFunding = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(fundingForm.supporting_files || [], 'cash-management', 'BUSINESS_FUNDING_SUPPORT')
      const { supporting_files, ...fundingPayload } = fundingForm
      await api.post('/v1/accounting/business-funding', {
        ...fundingPayload,
        attachment_tokens: attachmentTokens,
        branch_id: fundingForm.branch_id ? Number(fundingForm.branch_id) : null,
        cash_account_id: Number(fundingForm.cash_account_id), amount: Number(fundingForm.amount),
      })
      setFundingForm(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to record business funding.')) }
    finally { setSaving(false) }
  }

  const reverseFunding = async (event) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      await api.post(`/v1/accounting/business-funding/${reversingFunding.id}/reverse`, { reason_reference_id: Number(reversingFunding.reason_reference_id), reason: reversingFunding.reason })
      setReversingFunding(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to submit business funding reversal request.')) }
    finally { setSaving(false) }
  }

  const approveFundingReversal = async () => {
    if (!fundingReversalApproval) return
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/accounting/business-funding/reversal-requests/${fundingReversalApproval.id}/approve`)
      setFundingReversalApproval(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to approve business funding reversal.')) }
    finally { setSaving(false) }
  }

  const rejectFundingReversal = async (event) => {
    event.preventDefault()
    if (!fundingReversalReject) return
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/accounting/business-funding/reversal-requests/${fundingReversalReject.id}/reject`, { review_reason_reference_id: Number(fundingReversalReject.review_reason_reference_id), notes: fundingReversalReject.notes })
      setFundingReversalReject(null); await load()
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to reject business funding reversal.')) }
    finally { setSaving(false) }
  }

  const canApproveFundingReversal = hasPermission('cash-management', 'APPROVE_REVERSAL')

  return <div className="page-stack accounting-page">
    <Heading title="Cash Management" subtitle="Ledger-derived cash balances. Use Record Business Funding for new owner capital or owner loans after go-live; opening balances remain exclusive to the cutover setup." onRefresh={load} action={<button className="primary-button" type="button" onClick={openFunding}>Record Business Funding</button>} />
    {message ? <div className="form-alert error">{message}</div> : null}
    <section className="metric-grid accounting-metric-grid"><article className="metric-card"><span>Total Cash & Cash Equivalents</span><strong>{peso(data.summary?.total)}</strong></article></section>
    <Filters {...{ filters, setFilters, options: data.options, onApply: load }} hideFrom />
    <section className="content-card cash-balance-section">
      <div className="section-heading"><div><h2>Cash &amp; Bank Balances</h2><p>Balances come directly from posted accounting journals.</p></div></div>
      <div className="cash-balance-grid">
        {data.rows.map((row) => {
          const balance = Number(row.balance || 0)
          return <article className={`cash-balance-card ${balance < 0 ? 'is-negative' : ''}`} key={row.id}>
            <div className="cash-balance-card-head"><span className="cash-balance-code">{row.code}</span></div>
            <strong className="cash-balance-name">{row.name}</strong>
            <div className="cash-balance-amount">{peso(balance)}</div>
          </article>
        })}
        {!data.rows.length ? <div className="empty-state cash-balance-empty">No cash or bank balances available for the selected filters.</div> : null}
      </div>
    </section>
    <section className="content-card"><div className="section-heading"><div><h2>Owner Funding History</h2><p>Capital contributions and owner loans are separate from sales and do not affect current-period revenue.</p></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Code</th><th>Type</th><th>Received In</th><th>Amount</th><th>Reference</th><th>Status</th><th>Action</th></tr></thead><tbody>{(funding.rows || []).map((row) => {
      const pending = row.pending_reversal
      return <tr key={row.id}><td>{dateLabel(row.funding_date)}</td><td><strong>{row.code}</strong></td><td>{row.funding_type === 'OWNER_LOAN' ? 'Owner Loan' : 'Capital Contribution'}</td><td>{row.cash_account ? `${row.cash_account.code} · ${row.cash_account.name}` : '—'}</td><td><strong>{peso(row.amount)}</strong></td><td>{row.reference || '—'}</td><td>{pending ? <span className="status-pill warning">Pending Approval</span> : <span className={`status-pill ${row.status === 'POSTED' ? 'success' : 'danger'}`}>{row.status}</span>}</td><td className="action-cell"><ActionMenu items={pending ? (canApproveFundingReversal ? [
        { label: 'Approve Reversal', onClick: () => setFundingReversalApproval(pending) },
        { label: 'Reject Reversal', onClick: () => setFundingReversalReject({ ...pending, review_reason_reference_id: '', notes: '' }) },
      ] : []) : [row.status === 'POSTED' && hasPermission('cash-management', 'REVERSE') ? { label: 'Request Reversal', onClick: () => setReversingFunding({ ...row, reason_reference_id: '', reason: '' }) } : null]} /></td></tr>
    })}{!(funding.rows || []).length ? <tr><td colSpan="8"><div className="empty-state">No post-go-live owner funding recorded.</div></td></tr> : null}</tbody></table></div></section>
    {fundingForm ? <Modal title="Record Business Funding" subtitle="Choose Capital Contribution when the owner permanently adds equity, or Owner Loan when the business is expected to repay the amount." onClose={() => setFundingForm(null)}><form onSubmit={saveFunding}><div className="form-grid">
      <label className="form-field"><span>Funding Date</span><input required type="date" value={fundingForm.funding_date} onChange={(e) => setFundingForm((c) => ({ ...c, funding_date: e.target.value }))} /></label>
      <label className="form-field"><span>Funding Type</span><SearchableSelect required value={fundingForm.funding_type} onChange={(e) => setFundingForm((c) => ({ ...c, funding_type: e.target.value }))}>{(funding.options?.funding_types || []).map((row) => <option key={row.value} value={row.value}>{row.label}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Branch</span><SearchableSelect value={fundingForm.branch_id} onChange={(e) => setFundingForm((c) => ({ ...c, branch_id: e.target.value }))}><option value="">Business-wide / No Branch</option>{(funding.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Money Received In</span><SearchableSelect required value={fundingForm.cash_account_id} onChange={(e) => setFundingForm((c) => ({ ...c, cash_account_id: e.target.value }))}>{(funding.options?.cash_accounts || []).map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={fundingForm.amount} onChange={(e) => setFundingForm((c) => ({ ...c, amount: e.target.value }))} /></label>
      <label className="form-field"><span>Reference</span><input value={fundingForm.reference} onChange={(e) => setFundingForm((c) => ({ ...c, reference: e.target.value }))} /></label>
      <label className="form-field accounting-span-all"><span>Notes</span><textarea rows="3" value={fundingForm.notes} onChange={(e) => setFundingForm((c) => ({ ...c, notes: e.target.value }))} /></label>
      <div className="accounting-span-all"><SupportingDocumentField files={fundingForm.supporting_files || []} onChange={(files) => setFundingForm((c) => ({ ...c, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach a bank transfer, capital contribution proof, owner loan document or equivalent support." /></div>
    </div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setFundingForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Posting...' : 'Post Funding'}</button></div></form></Modal> : null}
    {reversingFunding ? <Modal title={`Request Reversal · ${reversingFunding.code}`} subtitle="The request must be approved before any counter-entry is posted." onClose={() => setReversingFunding(null)}><form onSubmit={reverseFunding}><WorkflowReasonSelect context="BUSINESS_FUNDING_REVERSAL" value={reversingFunding.reason_reference_id} onChange={(e) => setReversingFunding((c) => ({ ...c, reason_reference_id: e.target.value }))}/><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversingFunding.reason} onChange={(e) => setReversingFunding((c) => ({ ...c, reason: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversingFunding(null)}>Cancel</button><button className="danger-button" type="submit" disabled={saving}>Submit for Approval</button></div></form></Modal> : null}
    {fundingReversalApproval ? <ActionConfirmationModal title="Approve Funding Reversal" message={`Approve reversal request ${fundingReversalApproval.code}? The system will post the counter-entry after approval.`} details={[{ label: 'Request', value: fundingReversalApproval.code }, { label: 'Reason', value: fundingReversalApproval.reason }]} confirmLabel="Approve Reversal" danger busy={saving} onCancel={() => setFundingReversalApproval(null)} onConfirm={approveFundingReversal} /> : null}
    {fundingReversalReject ? <Modal title="Reject Funding Reversal" subtitle={fundingReversalReject.code} onClose={() => setFundingReversalReject(null)}><form onSubmit={rejectFundingReversal}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={fundingReversalReject.review_reason_reference_id} onChange={(e) => setFundingReversalReject((c) => ({ ...c, review_reason_reference_id: e.target.value }))}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={fundingReversalReject.notes} onChange={(e) => setFundingReversalReject((c) => ({ ...c, notes: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setFundingReversalReject(null)}>Cancel</button><button className="danger-button" type="submit" disabled={saving}>Reject Request</button></div></form></Modal> : null}
  </div>
}

export function CashFlowPage() {
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const load = useCallback(async () => { const response = await api.get('/v1/accounting/cash-flow', { params: { ...filters, branch_id: filters.branch_id || undefined } }); setData(response.data) }, [filters])
  useEffect(() => { load() }, [])
  return <div className="page-stack accounting-page"><Heading title="Cash Flow Statement" subtitle="Ledger-derived cash movement grouped into Operating, Investing, and Financing activities. Refund Due is non-cash until the approved Refund actually returns money." onRefresh={load} /><section className="cash-flow-card-grid">
    <article className={`cash-balance-card cash-flow-card ${Number(data.summary?.opening_cash || 0) < 0 ? 'is-negative' : ''}`}><strong className="cash-balance-name cash-flow-card-label">Opening Cash</strong><div className="cash-balance-amount">{peso(data.summary?.opening_cash)}</div></article>
    <article className={`cash-balance-card cash-flow-card ${Number(data.summary?.ending_cash || 0) < 0 ? 'is-negative' : ''}`}><strong className="cash-balance-name cash-flow-card-label">Ending Cash</strong><div className="cash-balance-amount">{peso(data.summary?.ending_cash)}</div></article>
    <article className={`cash-balance-card cash-flow-card ${Number(data.summary?.operating || 0) < 0 ? 'is-negative' : ''}`}><strong className="cash-balance-name cash-flow-card-label">Operating</strong><div className="cash-balance-amount">{peso(data.summary?.operating)}</div></article>
    <article className={`cash-balance-card cash-flow-card ${Number(data.summary?.investing || 0) < 0 ? 'is-negative' : ''}`}><strong className="cash-balance-name cash-flow-card-label">Investing</strong><div className="cash-balance-amount">{peso(data.summary?.investing)}</div></article>
    <article className={`cash-balance-card cash-flow-card ${Number(data.summary?.financing || 0) < 0 ? 'is-negative' : ''}`}><strong className="cash-balance-name cash-flow-card-label">Financing</strong><div className="cash-balance-amount">{peso(data.summary?.financing)}</div></article>
  </section><Filters {...{ filters, setFilters, options: data.options, onApply: load }} /><section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Journal</th><th>Reference</th><th>Description</th><th>Classification</th><th>Cash Change</th></tr></thead><tbody>{data.rows.map((row)=><tr key={`${row.journal_code}-${row.date}`}><td>{dateLabel(row.date)}</td><td><strong>{row.journal_code}</strong></td><td>{row.reference || '—'}</td><td>{row.description}</td><td><span className="status-pill neutral">{row.classification}</span></td><td><strong>{peso(row.cash_change)}</strong></td></tr>)}</tbody><tfoot><tr><td colSpan="5"><strong>Net Change in Cash</strong></td><td><strong>{peso(data.summary?.net_change)}</strong></td></tr></tfoot></table></div></section></div>
}

export function FinancialStatementsPage() {
  const currentYear = new Date().getFullYear()
  const [tab, setTab] = useState('INCOME')
  const [view, setView] = useState('AMOUNT')
  const [filters, setFilters] = useState({ from: `${currentYear}-01-01`, to: `${currentYear}-12-31`, year: currentYear, branch_id: '', basis: 'ACCRUAL', cost_basis: 'SYSTEM' })
  const [data, setData] = useState({ income_statement: {}, income_statement_yearly: { rows: [], summary: {}, totals: {} }, balance_sheet: {}, options: {} })
  const load = useCallback(async () => { const response = await api.get('/v1/accounting/financial-statements', { params: { ...filters, branch_id: filters.branch_id || undefined } }); setData(response.data) }, [filters])
  useEffect(() => { load() }, [])
  const yearly = data.income_statement_yearly || { rows: [], summary: {}, totals: {} }
  const balance = data.balance_sheet || {}
  const months = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER']
  const amountCell = (value, index) => {
    if (view === 'AMOUNT') return peso(value)
    const denominator = Number(yearly.summary?.net_revenue?.[index] || 0)
    return denominator ? `${((Number(value || 0) / denominator) * 100).toFixed(0)}%` : '—'
  }
  const totalCell = (value) => {
    if (view === 'AMOUNT') return peso(value)
    const denominator = Number(yearly.totals?.net_revenue || 0)
    return denominator ? `${((Number(value || 0) / denominator) * 100).toFixed(0)}%` : '—'
  }
  const grouped = (yearly.rows || []).reduce((out, row) => { const key = `${row.section}|${row.group_code}`; if (!out[key]) out[key] = { section: row.section, group_code: row.group_code, group_name: row.group_name, rows: [] }; out[key].rows.push(row); return out }, {})
  const summaryRow = (label, key, emphasis='') => <tr className={`statement-month-summary ${emphasis}`}><td><strong>{label}</strong></td>{(yearly.summary?.[key] || Array(12).fill(0)).map((value,i)=><td key={i}><strong>{view==='PERCENT' && key==='net_revenue' ? '100%' : amountCell(value,i)}</strong></td>)}<td><strong>{view==='PERCENT' && key==='net_revenue' ? '100%' : totalCell(yearly.totals?.[key])}</strong></td></tr>
  const bsSection = (title, rows) => <><tr className="statement-section"><td colSpan="2"><strong>{title}</strong></td></tr>{(rows || []).map((row) => <tr key={row.code}><td>{row.code} · {row.name}</td><td>{peso(row.amount)}</td></tr>)}</>
  return <div className="page-stack accounting-page"><Heading title="Financial Statements" subtitle="Income Statement and Balance Sheet are separated for clearer management reporting." onRefresh={load} />
    <div className="statement-main-tabs"><button type="button" className={tab==='INCOME'?'active':''} onClick={()=>setTab('INCOME')}>Income Statement</button><button type="button" className={tab==='BALANCE'?'active':''} onClick={()=>setTab('BALANCE')}>Balance Sheet</button></div>
    <section className="content-card"><div className="financial-statement-filters">
      {tab==='INCOME' ? <label className="form-field"><span>Year</span><SearchableSelect value={filters.year} onChange={(e)=>{const year=Number(e.target.value);setFilters((c)=>({...c,year,from:`${year}-01-01`,to:`${year}-12-31`}))}}>{Array.from({length:7},(_,i)=>currentYear-4+i).map((year)=><option key={year}>{year}</option>)}</SearchableSelect></label> : <label className="form-field"><span>As of</span><input type="date" value={filters.to} onChange={(e)=>setFilters((c)=>({...c,to:e.target.value}))} /></label>}
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e)=>setFilters((c)=>({...c,branch_id:e.target.value}))}><option value="">All Branches</option>{(data.options?.branches||[]).map((branch)=><option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Accounting Basis</span><SearchableSelect value={filters.basis} onChange={(e)=>setFilters((c)=>({...c,basis:e.target.value}))}><option value="ACCRUAL">Accrual</option><option value="CASH">Cash</option></SearchableSelect></label>
      {tab==='INCOME' ? <label className="form-field"><span>COGS Basis</span><SearchableSelect value={filters.cost_basis} onChange={(e)=>setFilters((c)=>({...c,cost_basis:e.target.value}))}><option value="SYSTEM">System COGS</option><option value="AUDIT_ADJUSTED">Audit-Adjusted COGS</option></SearchableSelect></label> : null}
      <div className="accounting-filter-actions"><button className="secondary-button" type="button" onClick={load}>Apply</button></div>
    </div></section>
    {tab==='INCOME' ? <section className="content-card"><div className="statement-view-toggle"><button type="button" className={view==='AMOUNT'?'active':''} onClick={()=>setView('AMOUNT')}>PHP Amount</button><button type="button" className={view==='PERCENT'?'active':''} onClick={()=>setView('PERCENT')}>Common Size %</button></div><div className="table-wrap"><table className="data-table yearly-income-table"><thead><tr><th>Income Statement Group / Account</th>{months.map((month)=><th key={month}>{month}</th>)}<th>FULL YEAR</th></tr></thead><tbody>
      {summaryRow('Gross Sales','gross_revenue')}{summaryRow('Discounts / Returns','discounts_returns')}{summaryRow('NET SALES','net_revenue','major')}
      {Object.values(grouped).filter((group)=>group.section==='COST_OF_SALES').map((group)=><FragmentGroup key={`c-${group.group_code}`} group={group} amountCell={amountCell} totalCell={totalCell} />)}
      {summaryRow('COST OF SALES','cost_of_sales','major')}{summaryRow('GROSS PROFIT','gross_profit','major')}
      {Object.values(grouped).filter((group)=>group.section==='OPERATING_EXPENSE').map((group)=><FragmentGroup key={`o-${group.group_code}`} group={group} amountCell={amountCell} totalCell={totalCell} />)}
      {summaryRow('TOTAL OPERATING EXPENSE','operating_expenses','major')}{summaryRow('NET PROFIT','net_income','net')}
    </tbody></table></div></section> : <section className="content-card"><div className="table-wrap"><table className="data-table statement-table"><tbody>{bsSection('Assets',balance.assets)}<tr className="statement-total"><td>Total Assets</td><td>{peso(balance.asset_total)}</td></tr>{bsSection('Liabilities',balance.liabilities)}<tr className="statement-total"><td>Total Liabilities</td><td>{peso(balance.liability_total)}</td></tr>{bsSection('Equity',balance.equity)}<tr className="statement-total"><td>Total Equity</td><td>{peso(balance.equity_total)}</td></tr></tbody></table></div></section>}
  </div>
}

function FragmentGroup({ group, amountCell, totalCell }) {
  return <><tr className="statement-group-row"><td colSpan="14"><strong>{group.group_name}</strong></td></tr>{group.rows.map((row)=><tr key={row.code}><td>{row.code} · {row.name}</td>{row.months.map((value,i)=><td key={i}>{amountCell(value,i)}</td>)}<td>{totalCell(row.total)}</td></tr>)}</>
}

export function DailyClosingPage() {
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], options: {} })
  const [closing, setClosing] = useState(null)
  const [reopening, setReopening] = useState(null)
  const [message, setMessage] = useState('')
  const load = useCallback(async () => { try { const response = await api.get('/v1/accounting/daily-closing', { params: { ...filters, branch_id: filters.branch_id || undefined } }); setData(response.data) } catch (error) { setMessage(getApiErrorMessage(error)) } }, [filters])
  useEffect(() => { load() }, [])
  async function close(event) { event.preventDefault(); await api.post('/v1/accounting/daily-closing', { ...closing, branch_id: Number(closing.branch_id) }); setClosing(null); await load() }
  async function reopen(event) { event.preventDefault(); await api.post(`/v1/accounting/daily-closing/${reopening.id}/reopen`, { reason: reopening.reason }); setReopening(null); await load() }
  return <div className="page-stack accounting-page"><section className="page-heading live-page-heading"><div><h1 className="live-page-title">Accounting Daily Closing <PageRefreshButton label="Refresh daily closing" onRefresh={load} /></h1><p>Lock accounting dates by branch after review. Closed dates reject new accounting journals until an authorized reopen.</p></div><button className="primary-button" type="button" onClick={() => setClosing({ branch_id: data.options?.branches?.[0]?.id || '', closing_date: today(), notes: '' })}>Close Day</button></section>{message ? <div className="form-alert error">{message}</div> : null}<Filters {...{ filters, setFilters, options: data.options, onApply: load }} /><section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Branch</th><th>Status</th><th>Closed By</th><th>Notes / Reopen Reason</th><th>Action</th></tr></thead><tbody>{data.rows.map((row) => <tr key={row.id}><td>{dateLabel(row.closing_date)}</td><td>{row.branch?.name}</td><td><span className={`status-pill ${row.status === 'CLOSED' ? 'success' : 'warning'}`}>{row.status}</span></td><td>{row.closed_by || '—'}</td><td>{row.reopen_reason || row.notes || '—'}</td><td>{row.status === 'CLOSED' ? <button className="secondary-button" type="button" onClick={() => setReopening({ ...row, reason: '' })}>Reopen</button> : '—'}</td></tr>)}</tbody></table></div></section>
    {closing ? <Modal title="Close Accounting Day" subtitle="This records the branch/date as closed for accounting control." onClose={() => setClosing(null)}><form onSubmit={close}><div className="form-grid"><label className="form-field"><span>Branch</span><SearchableSelect required value={closing.branch_id} onChange={(e) => setClosing((c) => ({ ...c, branch_id: e.target.value }))}>{(data.options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Date</span><input required type="date" value={closing.closing_date} onChange={(e) => setClosing((c) => ({ ...c, closing_date: e.target.value }))} /></label><label className="form-field accounting-span-all"><span>Notes</span><textarea rows="3" value={closing.notes} onChange={(e) => setClosing((c) => ({ ...c, notes: e.target.value }))} /></label></div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setClosing(null)}>Cancel</button><button className="primary-button" type="submit">Close Day</button></div></form></Modal> : null}
    {reopening ? <Modal title="Reopen Accounting Day" subtitle="A reason is required and remains in the audit trail." onClose={() => setReopening(null)}><form onSubmit={reopen}><label className="form-field"><span>Reason</span><textarea required minLength="5" rows="4" value={reopening.reason} onChange={(e) => setReopening((c) => ({ ...c, reason: e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReopening(null)}>Cancel</button><button className="danger-button" type="submit">Reopen</button></div></form></Modal> : null}
  </div>
}
