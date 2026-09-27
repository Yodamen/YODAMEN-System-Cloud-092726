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
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './accounting-expense.css'

function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function monthStart() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01` }
function peso(value) { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0)) }
function dateLabel(value) { if (!value) return '—'; return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`)) }
function dateTimeLabel(value) { if (!value) return '—'; return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value)) }
function statusClass(status) { if (['POSTED','PAID'].includes(status)) return 'success'; if (status === 'REVERSED') return 'danger'; if (['DRAFT','UNPAID'].includes(status)) return 'warning'; return 'neutral' }

function makeForm(branchId = '') {
  return {
    id: null,
    branch_id: branchId,
    expense_date: today(),
    vendor_payee: '',
    reference: '',
    description: '',
    payment_status: 'PAID',
    payment_splits: [{ funding_account_id: '', amount: '', reference: '' }],
    notes: '',
    lines: [{ account_id: '', description: '', amount: '' }],
  }
}

export default function ExpensesPage() {
  const { hasPermission, user } = useAuth()
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '', status: 'ALL', payment_status: 'ALL', funding_account_id: '', search: '' })
  const [form, setForm] = useState(null)
  const [supportingFiles, setSupportingFiles] = useState([])
  const [detail, setDetail] = useState(null)
  const [paying, setPaying] = useState(null)
  const [reversing, setReversing] = useState(null)
  const [reversalApproval, setReversalApproval] = useState(null)
  const [reversalReject, setReversalReject] = useState(null)
  const [posting, setPosting] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/expenses', { params: { ...next, branch_id: next.branch_id || undefined, status: next.status === 'ALL' ? undefined : next.status, payment_status: next.payment_status === 'ALL' ? undefined : next.payment_status, funding_account_id: next.funding_account_id || undefined, search: next.search || undefined } })
    setData(response.data)
    return response.data
  }, [filters])

  useEffect(() => { load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load expenses.'))).finally(() => setLoading(false)) }, [])

  const lineTotal = useMemo(() => (form?.lines || []).reduce((sum, line) => sum + Number(line.amount || 0), 0), [form])

  async function apply(event) { event.preventDefault(); setLoading(true); setMessage(''); try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load expenses.')) } finally { setLoading(false) } }

  function openCreate() {
    const defaultBranchId = user?.default_branch?.id || data.options?.branches?.[0]?.id || ''
    const next = makeForm(defaultBranchId)
    next.payment_splits = [{ funding_account_id: data.options?.funding_sources?.[0]?.id ? String(data.options.funding_sources[0].id) : '', amount: '', reference: '' }]
    setSupportingFiles([])
    setForm(next)
  }

  function openEdit(row) {
    setSupportingFiles([])
    setForm({
      id: row.id,
      branch_id: row.branch?.id || '',
      expense_date: row.expense_date,
      vendor_payee: row.vendor_payee,
      reference: row.reference || '',
      description: row.description || '',
      payment_status: row.payment_status,
      payment_splits: (row.payment_splits?.length ? row.payment_splits : [{ funding_account_id: row.payment_account?.id || '', amount: row.total_amount || '', reference: row.payment_reference || '' }]).map((split) => ({ funding_account_id: String(split.funding_account_id || split.cash_account_id || row.payment_account?.id || ''), amount: String(split.amount || ''), reference: split.reference || '' })),
      notes: row.notes || '',
      lines: row.lines.map((line) => ({ account_id: line.account_id, description: line.description || '', amount: String(line.amount) })),
    })
  }

  function setLine(index, key, value) { setForm((current) => ({ ...current, lines: current.lines.map((line, i) => i === index ? { ...line, [key]: value } : line) })) }
  function addLine() { setForm((current) => ({ ...current, lines: [...current.lines, { account_id: '', description: '', amount: '' }] })) }
  function removeLine(index) { setForm((current) => ({ ...current, lines: current.lines.length === 1 ? current.lines : current.lines.filter((_, i) => i !== index) })) }
  function setPaymentSplit(index, key, value) { setForm((current) => ({ ...current, payment_splits: current.payment_splits.map((row, i) => i === index ? { ...row, [key]: value } : row) })) }
  function addPaymentSplit() { setForm((current) => ({ ...current, payment_splits: [...(current.payment_splits || []), { funding_account_id: '', amount: '', reference: '' }] })) }
  function removePaymentSplit(index) { setForm((current) => ({ ...current, payment_splits: current.payment_splits.length === 1 ? current.payment_splits : current.payment_splits.filter((_, i) => i !== index) })) }

  async function saveDraft(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(supportingFiles, 'expenses', 'EXPENSE_SUPPORT')
      const payload = { ...form, attachment_tokens: attachmentTokens, branch_id: Number(form.branch_id), payment_splits: form.payment_status === 'PAID' ? form.payment_splits.map((split) => ({ funding_account_id: Number(split.funding_account_id), amount: Number(split.amount), reference: split.reference || null })) : [], lines: form.lines.map((line) => ({ account_id: Number(line.account_id), description: line.description || null, amount: Number(line.amount) })) }
      await (form.id ? api.put(`/v1/expenses/${form.id}`, payload) : api.post('/v1/expenses', payload))
      setForm(null); setSupportingFiles([]); await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to save expense.')) } finally { setSaving(false) }
  }

  function postExpense(row) {
    setPosting(row)
  }

  async function confirmPostExpense() {
    if (!posting) return
    setSaving(true); setMessage('')
    try {
      await api.post(`/v1/expenses/${posting.id}/post`)
      setPosting(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to post expense.'))
    } finally {
      setSaving(false)
    }
  }

  async function loadDetail(row) {
    setMessage('')
    try { const response = await api.get(`/v1/expenses/${row.id}`); setDetail(response.data) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load expense details.')) }
  }

  async function markPaid(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      await api.post(`/v1/expenses/${paying.id}/mark-paid`, { payment_splits: paying.payment_splits.map((split) => ({ funding_account_id: Number(split.funding_account_id), amount: Number(split.amount), reference: split.reference || null })) })
      setPaying(null); await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to mark expense paid.')) } finally { setSaving(false) }
  }

  async function reverse(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try { const attachmentTokens = await uploadSupportingDocuments(reversing.supporting_files || [], 'expenses', 'EXPENSE_REVERSAL_SUPPORT'); await api.post(`/v1/expenses/${reversing.id}/reverse`, { reason_reference_id: Number(reversing.reason_reference_id), reason: reversing.reason, attachment_tokens: attachmentTokens }); setReversing(null); await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to submit expense reversal request.')) } finally { setSaving(false) }
  }

  async function approveReversal() {
    if (!reversalApproval) return
    setSaving(true); setMessage('')
    try { await api.post(`/v1/expenses/reversal-requests/${reversalApproval.id}/approve`, {}); setReversalApproval(null); await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to approve expense reversal.')) } finally { setSaving(false) }
  }

  async function rejectReversal(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try { await api.post(`/v1/expenses/reversal-requests/${reversalReject.id}/reject`, { review_reason_reference_id: Number(reversalReject.review_reason_reference_id), notes: reversalReject.notes }); setReversalReject(null); await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to reject expense reversal.')) } finally { setSaving(false) }
  }

  async function openAttachment(expense) {
    try {
      const response = await api.get(`/v1/expenses/${expense.id}/attachment`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data); window.open(url, '_blank', 'noopener,noreferrer'); window.setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to open supporting document.')) }
  }

  return <div className="page-stack accounting-page">
    <section className="page-heading live-page-heading">
      <div><h1 className="live-page-title">Expenses <PageRefreshButton label="Refresh expenses" disabled={loading || saving} onRefresh={() => load(filters)} /></h1><p>Record, classify, post, settle, and reverse business expenses with system-generated double-entry journals.</p></div>
      {hasPermission('expenses', 'CREATE') ? <button className="primary-button" type="button" onClick={openCreate}>Add Expense</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="metric-grid accounting-metric-grid">
      <article className="metric-card"><span>Posted Expenses</span><strong>{peso(data.summary?.posted_total)}</strong><small>Excludes reversed records</small></article>
      <article className="metric-card"><span>Paid</span><strong>{peso(data.summary?.paid_total)}</strong><small>Cash already released</small></article>
      <article className="metric-card"><span>Unpaid</span><strong>{peso(data.summary?.unpaid_total)}</strong><small>Posted to Accounts Payable</small></article>
      <article className="metric-card"><span>Drafts</span><strong>{data.summary?.draft_count || 0}</strong><small>Not yet posted</small></article>
    </section>

    <section className="content-card"><form className="expense-filter-grid" onSubmit={apply}>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All Branches</option>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(e) => setFilters((c) => ({ ...c, status: e.target.value }))}><option value="ALL">All</option><option value="DRAFT">Draft</option><option value="POSTED">Posted</option><option value="REVERSED">Reversed</option></SearchableSelect></label>
      <label className="form-field"><span>Payment</span><SearchableSelect value={filters.payment_status} onChange={(e) => setFilters((c) => ({ ...c, payment_status: e.target.value }))}><option value="ALL">All</option><option value="PAID">Paid</option><option value="UNPAID">Unpaid</option></SearchableSelect></label>
      <label className="form-field"><span>Fund Source</span><SearchableSelect value={filters.funding_account_id} onChange={(e) => setFilters((c) => ({ ...c, funding_account_id: e.target.value }))}><option value="">All Fund Sources</option>{(data.options?.funding_sources || []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}</SearchableSelect></label>
      <label className="form-field expense-filter-search"><span>Search</span><input value={filters.search} onChange={(e) => setFilters((c) => ({ ...c, search: e.target.value }))} placeholder="Expense ID, payee or reference" /></label>
      <div className="accounting-filter-actions"><button className="secondary-button" type="submit">Apply</button></div>
    </form></section>

    <section className="content-card accounting-table-card"><div className="table-wrap"><table className="data-table accounting-table expense-table">
      <thead><tr><th>Date</th><th>Expense</th><th>Payee</th><th>Classification</th><th>Payment</th><th>Amount</th><th>Encoded By</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>{data.rows.map((row) => <tr key={row.id}>
        <td>{dateLabel(row.expense_date)}<span className="table-subline">{row.branch?.name || '—'}</span></td>
        <td><strong>{row.code}</strong><span className="table-subline">{row.reference || 'No reference'}</span></td>
        <td><strong>{row.vendor_payee}</strong><span className="table-subline">{row.description || '—'}</span></td>
        <td>{row.lines.slice(0, 2).map((line) => <span key={line.id || `${line.account_id}-${line.line_no}`} className="expense-account-line">{line.account?.code} · {line.account?.name}</span>)}{row.lines.length > 2 ? <span className="table-subline">+{row.lines.length - 2} more line(s)</span> : null}</td>
        <td><span className={`status-pill ${statusClass(row.payment_status)}`}>{row.payment_status}</span><span className="table-subline">{row.payment_status === 'UNPAID' ? 'Accounts Payable' : (row.payment_splits || []).map((split) => split.funding_source || split.payment_method || split.cash_account).filter(Boolean).join(' + ') || row.payment_method || '—'}</span></td>
        <td><strong>{peso(row.total_amount)}</strong></td>
        <td>{row.created_by?.name || '—'}</td>
        <td>{row.pending_reversal ? <span className="status-pill warning">Pending Approval</span> : <span className={`status-pill ${statusClass(row.status)}`}>{row.status}</span>}</td>
        <td><ActionMenu items={[
          { label: 'View Details', onClick: () => loadDetail(row) },
          row.status === 'DRAFT' && hasPermission('expenses', 'EDIT') ? { label: 'Edit Draft', onClick: () => openEdit(row) } : null,
          row.status === 'DRAFT' && hasPermission('expenses', 'POST') ? { label: 'Post Expense', onClick: () => postExpense(row) } : null,
          row.status === 'POSTED' && row.payment_status === 'UNPAID' && hasPermission('expenses', 'MARK_PAID') ? { label: 'Mark Paid', onClick: () => setPaying({ id: row.id, code: row.code, payment_splits: [{ funding_account_id: data.options?.funding_sources?.[0]?.id ? String(data.options.funding_sources[0].id) : '', amount: String(row.total_amount || ''), reference: '' }] }) } : null,
          row.status === 'POSTED' && !row.pending_reversal && hasPermission('expenses', 'REVERSE') ? { label: 'Request Reversal', onClick: () => setReversing({ id: row.id, code: row.code, reason_reference_id: '', reason: '', supporting_files: [] }) } : null,
          row.pending_reversal && hasPermission('expenses', 'APPROVE_REVERSAL') ? { label: 'Approve Reversal', onClick: () => setReversalApproval({ ...row.pending_reversal, expense_code: row.code }) } : null,
          row.pending_reversal && hasPermission('expenses', 'APPROVE_REVERSAL') ? { label: 'Reject Reversal', onClick: () => setReversalReject({ ...row.pending_reversal, expense_code: row.code, review_reason_reference_id: '', notes: '' }) } : null,
        ]} /></td>
      </tr>)}{!data.rows.length && !loading ? <tr><td colSpan="9" className="empty-table-cell">No expenses found for the selected filters.</td></tr> : null}</tbody>
    </table></div></section>

    {form ? <Modal wide className="expense-entry-modal" title={form.id ? `Edit ${form.id ? 'Expense Draft' : 'Expense'}` : 'Add Expense'} subtitle="Save as Draft first. Posting creates the accounting entry and locks the financial record." onClose={() => setForm(null)}>
      <form onSubmit={saveDraft}>
        <div className="form-grid expense-header-form">
          <label className="form-field"><span>Expense Date</span><input type="date" required value={form.expense_date} onChange={(e) => setForm((c) => ({ ...c, expense_date: e.target.value }))} /></label>
          <label className="form-field"><span>Branch</span><SearchableSelect required value={form.branch_id} onChange={(e) => setForm((c) => ({ ...c, branch_id: e.target.value }))}><option value="">Select branch</option>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Payee</span><input required value={form.vendor_payee} onChange={(e) => setForm((c) => ({ ...c, vendor_payee: e.target.value }))} /></label>
          <label className="form-field"><span>Reference / OR / Invoice</span><input value={form.reference} onChange={(e) => setForm((c) => ({ ...c, reference: e.target.value }))} /></label>
          <label className="form-field accounting-span-2"><span>Description</span><input value={form.description} onChange={(e) => setForm((c) => ({ ...c, description: e.target.value }))} /></label>
          <label className="form-field"><span>Payment Status</span><SearchableSelect value={form.payment_status} onChange={(e) => setForm((c) => ({ ...c, payment_status: e.target.value }))}><option value="PAID">Paid</option><option value="UNPAID">Unpaid</option></SearchableSelect></label>
          {form.payment_status === 'UNPAID' ? <div className="expense-payable-note accounting-span-2">Posting this expense will credit <strong>Accounts Payable – Suppliers</strong>. Use Mark Paid later when cash is released.</div> : null}
        </div>

        {form.payment_status === 'PAID' ? <div className="expense-payment-section">
          <div className="expense-lines-heading"><div><h3>Funding Split</h3><p>Select the business funding source used to pay this expense. The total must equal the expense total.</p></div><button className="secondary-button" type="button" onClick={addPaymentSplit}>+ Add Funding Source</button></div>
          <div className="expense-payment-split-list">{(form.payment_splits || []).map((split, index) => <div className="expense-payment-split-row" key={index}>
            <label className="form-field"><span>Funding Source</span><SearchableSelect required value={split.funding_account_id} onChange={(e) => setPaymentSplit(index, 'funding_account_id', e.target.value)}><option value="">Select source</option>{(data.options?.funding_sources || []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}</SearchableSelect></label>
            <label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={split.amount} onChange={(e) => setPaymentSplit(index, 'amount', e.target.value)} /></label>
            <label className="form-field"><span>Reference</span><input value={split.reference} onChange={(e) => setPaymentSplit(index, 'reference', e.target.value)} placeholder="Optional" /></label>
            <button className="secondary-button compact-button" type="button" disabled={form.payment_splits.length === 1} onClick={() => removePaymentSplit(index)}>Remove</button>
          </div>)}</div>
        </div> : null}

        <div className="expense-lines-heading"><div><h3>Expense Lines</h3><p>Split one receipt across multiple expense or asset classifications when needed.</p></div><button className="secondary-button" type="button" onClick={addLine}>+ Add Line</button></div>
        <div className="expense-line-list">{form.lines.map((line, index) => <div className="expense-line-card" key={index}>
          <div className="expense-line-number">{index + 1}</div>
          <label className="form-field expense-line-account"><span>Account</span><SearchableSelect required value={line.account_id} onChange={(e) => setLine(index, 'account_id', e.target.value)}><option value="">Select account</option>{(data.options?.expense_accounts || []).map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</SearchableSelect></label>
          <label className="form-field expense-line-description"><span>Line Description</span><input value={line.description} placeholder="Optional" onChange={(e) => setLine(index, 'description', e.target.value)} /></label>
          <label className="form-field expense-line-amount"><span>Amount</span><input required min="0.01" step="0.01" type="number" value={line.amount} onChange={(e) => setLine(index, 'amount', e.target.value)} /></label>
          <button className="secondary-button compact-button expense-line-remove" type="button" disabled={form.lines.length === 1} onClick={() => removeLine(index)}>Remove</button>
        </div>)}</div>
        <div className="expense-lines-total"><span>Total</span><strong>{peso(lineTotal)}</strong></div>

        <div className="form-grid expense-footer-form">
          <label className="form-field accounting-span-2"><span>Notes</span><textarea rows="3" value={form.notes} onChange={(e) => setForm((c) => ({ ...c, notes: e.target.value }))} /></label>
          <div className="accounting-span-2"><SupportingDocumentField files={supportingFiles} onChange={setSupportingFiles} label="Supporting Document" hint="Required before posting. You may save the expense as Draft first, then attach the receipt, invoice, billing statement or other proof before Post Expense." /></div>
        </div>
        <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save Draft'}</button></div>
      </form>
    </Modal> : null}

    {detail ? <Modal wide className="expense-detail-modal" title={detail.expense.code} subtitle={`${detail.expense.vendor_payee} · ${dateLabel(detail.expense.expense_date)}`} onClose={() => setDetail(null)} headerAction={detail.expense.attachment ? <button className="secondary-button" type="button" onClick={() => openAttachment(detail.expense)}>Open Document</button> : null}>
      <div className="expense-detail-summary">
        <div><span>Amount</span><strong>{peso(detail.expense.total_amount)}</strong></div><div><span>Status</span><strong><span className={`status-pill ${statusClass(detail.expense.status)}`}>{detail.expense.status}</span></strong></div><div><span>Payment</span><strong><span className={`status-pill ${statusClass(detail.expense.payment_status)}`}>{detail.expense.payment_status}</span></strong></div><div><span>Branch</span><strong>{detail.expense.branch?.name}</strong></div>
      </div>
      <div className="expense-detail-grid"><div><span>Reference</span><strong>{detail.expense.reference || '—'}</strong></div><div><span>Funding Source</span><strong>{detail.expense.payment_status === 'UNPAID' ? 'Accounts Payable' : (detail.expense.payment_splits || []).map((split) => `${split.funding_source || split.payment_method || split.cash_account || 'Funding Source'} ${peso(split.amount)}`).join(' + ') || detail.expense.payment_method || '—'}</strong></div><div><span>Posted By</span><strong>{detail.expense.posted_by?.name || '—'}</strong></div><div><span>Posted At</span><strong>{dateTimeLabel(detail.expense.posted_at)}</strong></div>{detail.expense.reversal_reason ? <div className="accounting-span-all"><span>Reversal Reason</span><strong>{detail.expense.reversal_reason}</strong></div> : null}</div>
      {detail.expense.pending_reversal ? <div className="expense-payable-note"><strong>Reversal Pending Approval</strong><br />{detail.expense.pending_reversal.reason}<span className="table-subline">Requested by {detail.expense.pending_reversal.requested_by || '—'}</span></div> : null}
      <h3 className="expense-section-title">Expense Lines</h3><div className="table-wrap"><table className="data-table"><thead><tr><th>Account</th><th>Description</th><th>Amount</th></tr></thead><tbody>{detail.expense.lines.map((line) => <tr key={line.id}><td><strong>{line.account?.code}</strong> · {line.account?.name}</td><td>{line.description || '—'}</td><td><strong>{peso(line.amount)}</strong></td></tr>)}</tbody></table></div>
      <SupportingDocumentList entityType="EXPENSE" entityId={detail.expense.id} />
      <h3 className="expense-section-title">Accounting Journal</h3>{detail.journals.map((journal) => <div className="expense-journal-block" key={journal.id}><div className="expense-journal-heading"><strong>{journal.code}</strong><span className="status-pill success">{journal.source_event}</span></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Account</th><th>Memo</th><th>Debit</th><th>Credit</th></tr></thead><tbody>{journal.lines.map((line) => <tr key={line.id}><td>{line.account_code} · {line.account_name}</td><td>{line.memo || '—'}</td><td>{line.debit > 0 ? peso(line.debit) : '—'}</td><td>{line.credit > 0 ? peso(line.credit) : '—'}</td></tr>)}</tbody></table></div></div>)}
    </Modal> : null}

    {paying ? <Modal title={`Mark ${paying.code} Paid`} subtitle="Settle Accounts Payable using one or more business funding sources." onClose={() => setPaying(null)}><form onSubmit={markPaid}><div className="expense-payment-split-list">{paying.payment_splits.map((split, index) => <div className="expense-payment-split-row" key={index}><label className="form-field"><span>Funding Source</span><SearchableSelect required value={split.funding_account_id} onChange={(e) => setPaying((c) => ({ ...c, payment_splits: c.payment_splits.map((row,i)=>i===index?{...row,funding_account_id:e.target.value}:row) }))}><option value="">Select source</option>{(data.options?.funding_sources || []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={split.amount} onChange={(e) => setPaying((c) => ({ ...c, payment_splits: c.payment_splits.map((row,i)=>i===index?{...row,amount:e.target.value}:row) }))} /></label><label className="form-field"><span>Reference</span><input value={split.reference} onChange={(e) => setPaying((c) => ({ ...c, payment_splits: c.payment_splits.map((row,i)=>i===index?{...row,reference:e.target.value}:row) }))} /></label><button className="secondary-button compact-button" type="button" disabled={paying.payment_splits.length===1} onClick={() => setPaying((c)=>({...c,payment_splits:c.payment_splits.filter((_,i)=>i!==index)}))}>Remove</button></div>)}</div><div className="expense-lines-heading"><div></div><button className="secondary-button" type="button" onClick={() => setPaying((c)=>({...c,payment_splits:[...c.payment_splits,{funding_account_id:'',amount:'',reference:''}]}))}>+ Add Funding Source</button></div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setPaying(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>Mark Paid</button></div></form></Modal> : null}

    {posting ? <ActionConfirmationModal
      title="Post Expense"
      subtitle={posting.code}
      message="Posting creates the accounting journal and locks this expense from direct editing."
      details={[{ label: 'Payee', value: posting.vendor_payee || '—' }, { label: 'Amount', value: peso(posting.total_amount) }]}
      confirmLabel="Post Expense"
      busy={saving}
      onCancel={() => setPosting(null)}
      onConfirm={confirmPostExpense}
    /> : null}

    {reversing ? <Modal title={`Request Reversal · ${reversing.code}`} subtitle="The original expense stays posted until an authorized approver approves this reversal." onClose={() => setReversing(null)}><form onSubmit={reverse}><WorkflowReasonSelect context="EXPENSE_REVERSAL" value={reversing.reason_reference_id} onChange={(e) => setReversing((c) => ({ ...c, reason_reference_id: e.target.value }))} /><label className="form-field"><span>Details</span><textarea required minLength="5" rows="4" value={reversing.reason} onChange={(e) => setReversing((c) => ({ ...c, reason: e.target.value }))} /></label><SupportingDocumentField files={reversing.supporting_files || []} onChange={(files) => setReversing((c) => ({ ...c, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach additional evidence supporting the reversal request. The original expense documents remain preserved." /><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReversing(null)}>Cancel</button><button className="danger-button" type="submit" disabled={saving}>Submit for Approval</button></div></form></Modal> : null}

    {reversalApproval ? <ActionConfirmationModal title="Approve Expense Reversal" subtitle={reversalApproval.expense_code} message="Approval will preserve the original expense and post a reversing journal." confirmLabel="Approve & Reverse" busy={saving} onCancel={()=>setReversalApproval(null)} onConfirm={approveReversal} /> : null}

    {reversalReject ? <Modal title={`Reject Reversal · ${reversalReject.expense_code}`} onClose={()=>setReversalReject(null)}><form onSubmit={rejectReversal}><WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={reversalReject.review_reason_reference_id} onChange={e=>setReversalReject({...reversalReject,review_reason_reference_id:e.target.value})}/><label className="form-field"><span>Rejection Details</span><textarea required minLength="3" rows="4" value={reversalReject.notes} onChange={e=>setReversalReject({...reversalReject,notes:e.target.value})} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={()=>setReversalReject(null)}>Cancel</button><button className="danger-button" disabled={saving}>Reject Request</button></div></form></Modal> : null}
  </div>
}
