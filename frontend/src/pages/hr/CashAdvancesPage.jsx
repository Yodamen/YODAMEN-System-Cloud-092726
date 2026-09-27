import SearchableSelect from '../../components/common/SearchableSelect'
import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import SupportingDocumentList from '../../components/common/SupportingDocumentList'
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
const emptyForm = { personnel_id: '', issued_date: today, original_amount: '', deduction_per_payroll: '', purpose: '', notes: '', supporting_files: [] }

function statusLabel(status) {
  if (status === 'FULLY_PAID') return 'Fully Paid'
  if (status === 'WRITTEN_OFF') return 'Written Off'
  if (status === 'ACTIVE') return 'Active'
  if (status === 'DRAFT') return 'Draft'
  return status || '—'
}

function statusClass(status) {
  if (status === 'FULLY_PAID') return 'success'
  if (status === 'WRITTEN_OFF') return 'danger'
  if (status === 'ACTIVE') return 'success'
  return 'neutral'
}

function ledgerLabel(type) {
  if (type === 'ISSUE') return 'Advance Issued'
  if (type === 'REPAYMENT') return 'Manual Repayment'
  if (type === 'PAYROLL_DEDUCTION') return 'Payroll Deduction'
  if (type === 'WRITE_OFF') return 'Written Off'
  if (type === 'REVERSAL') return 'Reversal'
  return String(type || 'Activity').replaceAll('_', ' ')
}

export default function CashAdvancesPage() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [filters, setFilters] = useState({ personnel_id: '', status: 'ALL' })
  const [form, setForm] = useState(emptyForm)
  const [show, setShow] = useState(false)
  const [approval, setApproval] = useState(null)
  const [repayment, setRepayment] = useState(null)
  const [writeOff, setWriteOff] = useState(null)
  const [selected, setSelected] = useState(null)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  async function load(next = filters) {
    const response = await api.get('/v1/hr/cash-advances', { params: next })
    setData(response.data)
    return response.data
  }

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load cash advances.')))
  }, [])

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    try {
      const attachmentTokens = await uploadSupportingDocuments(form.supporting_files || [], 'cash-advances', 'CASH_ADVANCE_SUPPORT')
      const { supporting_files, ...payload } = form
      await api.post('/v1/hr/cash-advances', {
        ...payload,
        attachment_tokens: attachmentTokens,
        personnel_id: Number(form.personnel_id),
        original_amount: Number(form.original_amount),
        deduction_per_payroll: Number(form.deduction_per_payroll || 0),
      })
      setShow(false)
      setForm(emptyForm)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to create cash advance.'))
    } finally {
      setBusy(false)
    }
  }

  function requestApproval(row) {
    setApproval(row)
  }

  async function confirmApproval() {
    if (!approval) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/hr/cash-advances/${approval.id}/approve`)
      setApproval(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve cash advance.'))
    } finally {
      setBusy(false)
    }
  }

  function openRepayment(row) {
    setRepayment({
      row,
      amount: Math.min(Number(row.deduction_per_payroll || 0) || Number(row.outstanding_balance || 0), Number(row.outstanding_balance || 0)).toFixed(2),
      entry_date: today,
      notes: 'Manual repayment',
    })
  }

  async function submitRepayment(event) {
    event.preventDefault()
    if (!repayment) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/hr/cash-advances/${repayment.row.id}/repayments`, {
        amount: Number(repayment.amount),
        entry_date: repayment.entry_date,
        notes: repayment.notes || null,
      })
      setRepayment(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to record repayment.'))
    } finally {
      setBusy(false)
    }
  }

  async function submitWriteOff(event) {
    event.preventDefault()
    if (!writeOff) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/hr/cash-advances/${writeOff.row.id}/write-off`, { reason: writeOff.reason })
      setWriteOff(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to write off cash advance.'))
    } finally {
      setBusy(false)
    }
  }

  const canWriteOff = Boolean(user?.role?.is_super_admin)

  return <div className="page-stack">
    <div className="page-heading"><div><p className="eyebrow">HR & Payroll</p><h1>Cash Advances</h1><p>Auditable employee advances, repayments, and future payroll deductions.</p></div><button className="primary-button" onClick={() => setShow(true)}>+ New Cash Advance</button></div>
    {message ? <div className="form-alert error">{message}</div> : null}
    <section className="metric-grid"><article className="metric-card"><span>Outstanding</span><strong>{formatPeso(data?.totals?.outstanding)}</strong></article><article className="metric-card"><span>Active</span><strong>{data?.totals?.active ?? 0}</strong></article><article className="metric-card"><span>Draft</span><strong>{data?.totals?.draft ?? 0}</strong></article></section>
    <section className="content-card"><form className="attendance-filter-grid" onSubmit={(event) => { event.preventDefault(); load(filters) }}><label className="form-field"><span>Personnel</span><SearchableSelect value={filters.personnel_id} onChange={(event) => setFilters((current) => ({ ...current, personnel_id: event.target.value }))}><option value="">All Personnel</option>{(data?.personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}><option value="ALL">All Statuses</option><option value="DRAFT">Draft</option><option value="ACTIVE">Active</option><option value="FULLY_PAID">Fully Paid</option><option value="WRITTEN_OFF">Written Off</option></SearchableSelect></label><div className="attendance-filter-actions"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={() => { const next = { personnel_id: '', status: 'ALL' }; setFilters(next); load(next) }}>Reset</button></div></form></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Transaction</th><th>Date</th><th>Personnel</th><th>Advance</th><th>Per Payroll</th><th>Outstanding</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.id}><td><strong>{row.code}</strong></td><td>{formatDisplayDate(row.issued_date)}</td><td><strong>{row.personnel?.name}</strong><span className="table-subline">{row.personnel?.code}</span></td><td>{formatPeso(row.original_amount)}</td><td>{formatPeso(row.deduction_per_payroll)}</td><td><strong>{formatPeso(row.outstanding_balance)}</strong></td><td><span className={`status-pill ${statusClass(row.display_status)}`}>{statusLabel(row.display_status)}</span></td><td className="action-cell"><ActionMenu items={[
                    { label: 'View Transaction', onClick: () => setSelected(row) },
                    row.status === 'DRAFT' ? { label: 'Approve', onClick: () => requestApproval(row) } : null,
                    row.status === 'ACTIVE' ? { label: 'Repayment', onClick: () => openRepayment(row) } : null,
                    row.status === 'ACTIVE' && canWriteOff ? { label: 'Write Off Balance', onClick: () => setWriteOff({ row, reason: '' }) } : null,
                  ]} /></td></tr>)}</tbody></table></div>{!(data?.rows || []).length ? <div className="empty-state">No cash advances found.</div> : null}</section>

    {show ? <Modal title="New Cash Advance" subtitle="Create a draft employee cash advance before approval." onClose={() => setShow(false)} className="cash-advance-modal">
      <form className="cash-advance-form" onSubmit={submit}>
        <label className="form-field"><span>Personnel</span><SearchableSelect required value={form.personnel_id} onChange={(event) => setForm((current) => ({ ...current, personnel_id: event.target.value }))}><option value="">Select personnel</option>{(data?.personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Issue Date</span><input type="date" required value={form.issued_date} onChange={(event) => setForm((current) => ({ ...current, issued_date: event.target.value }))} /></label>
        <label className="form-field"><span>Amount</span><input type="number" step="0.01" min="0.01" required value={form.original_amount} onChange={(event) => setForm((current) => ({ ...current, original_amount: event.target.value }))} /></label>
        <label className="form-field"><span>Deduction per Payroll</span><input type="number" step="0.01" min="0" value={form.deduction_per_payroll} onChange={(event) => setForm((current) => ({ ...current, deduction_per_payroll: event.target.value }))} /></label>
        <label className="form-field full-span"><span>Purpose</span><input value={form.purpose} onChange={(event) => setForm((current) => ({ ...current, purpose: event.target.value }))} /></label>
        <label className="form-field full-span"><span>Notes</span><textarea rows="3" value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} /></label>
        <div className="full-span"><SupportingDocumentField files={form.supporting_files || []} onChange={(files) => setForm((current) => ({ ...current, supporting_files: files }))} label="Supporting Document" hint="Optional. Attach the employee request, acknowledgment, approval memo or other support." /></div>
        <div className="modal-actions full-span"><button type="button" className="secondary-button" onClick={() => setShow(false)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? 'Saving...' : 'Create Draft'}</button></div>
      </form>
    </Modal> : null}

    {approval ? <ActionConfirmationModal
      title="Approve Cash Advance"
      message="Approve this cash advance and recognize the employee receivable?"
      confirmLabel="Approve Cash Advance"
      busy={busy}
      details={[
        { label: 'Personnel', value: approval.personnel?.name },
        { label: 'Advance', value: formatPeso(approval.original_amount) },
        { label: 'Deduction per Payroll', value: formatPeso(approval.deduction_per_payroll) },
      ]}
      onCancel={() => setApproval(null)}
      onConfirm={confirmApproval}
    /> : null}

    {writeOff ? <Modal title="Write Off Cash Advance" subtitle="Permanent forgiveness of the outstanding employee receivable. This is different from waiving one payroll deduction." onClose={() => setWriteOff(null)} className="cash-advance-action-modal">
      <form className="form-grid cash-advance-action-form" onSubmit={submitWriteOff}>
        <div className="action-confirm-details form-span-2">
          <div><span>Personnel</span><strong>{writeOff.row.personnel?.name || '—'}</strong></div>
          <div><span>Outstanding Balance</span><strong>{formatPeso(writeOff.row.outstanding_balance)}</strong></div>
        </div>
        <label className="form-field form-span-2"><span>Mandatory Write-Off Reason</span><textarea rows="4" required value={writeOff.reason} onChange={(event) => setWriteOff((current) => ({ ...current, reason: event.target.value }))} /></label>
        <div className="modal-actions form-span-2"><button type="button" className="secondary-button" disabled={busy} onClick={() => setWriteOff(null)}>Cancel</button><button className="danger-button" disabled={busy || !writeOff.reason.trim()}>{busy ? 'Writing Off...' : 'Write Off Balance'}</button></div>
      </form>
    </Modal> : null}

    {repayment ? <Modal title="Record Cash Advance Repayment" subtitle={`${repayment.row.personnel?.name || ''} · ${repayment.row.code}`} onClose={() => setRepayment(null)} className="cash-advance-action-modal">
      <form className="form-grid cash-advance-action-form" onSubmit={submitRepayment}>
        <div className="action-confirm-details form-span-2">
          <div><span>Outstanding Balance</span><strong>{formatPeso(repayment.row.outstanding_balance)}</strong></div>
          <div><span>Scheduled per Payroll</span><strong>{formatPeso(repayment.row.deduction_per_payroll)}</strong></div>
        </div>
        <label className="form-field"><span>Repayment Amount</span><input type="number" step="0.01" min="0.01" max={Number(repayment.row.outstanding_balance || 0)} required value={repayment.amount} onChange={(event) => setRepayment((current) => ({ ...current, amount: event.target.value }))} /></label>
        <label className="form-field"><span>Repayment Date</span><input type="date" required value={repayment.entry_date} onChange={(event) => setRepayment((current) => ({ ...current, entry_date: event.target.value }))} /></label>
        <label className="form-field form-span-2"><span>Notes</span><textarea rows="3" value={repayment.notes} onChange={(event) => setRepayment((current) => ({ ...current, notes: event.target.value }))} /></label>
        <div className="modal-actions form-span-2"><button type="button" className="secondary-button" disabled={busy} onClick={() => setRepayment(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? 'Recording...' : 'Record Repayment'}</button></div>
      </form>
    </Modal> : null}

    {selected ? <Modal wide title={`Cash Advance · ${selected.code}`} subtitle={`${selected.personnel?.name || 'Personnel'} · ${formatDisplayDate(selected.issued_date)}`} onClose={() => setSelected(null)} headerAction={<span className={`status-pill ${statusClass(selected.display_status)}`}>{statusLabel(selected.display_status)}</span>}>
      <div className="cash-advance-detail-stack">
        <div className="cash-advance-history-summary">
          <div><span>Original Advance</span><strong>{formatPeso(selected.original_amount)}</strong></div>
          <div><span>Total Paid</span><strong>{formatPeso(selected.payment_total)}</strong><small>Manual + payroll deductions</small></div>
          <div><span>Written Off</span><strong>{formatPeso(selected.written_off_total)}</strong></div>
          <div><span>Outstanding</span><strong>{formatPeso(selected.outstanding_balance)}</strong></div>
        </div>
        <div className="cash-advance-detail-meta">
          <div><span>Scheduled per Payroll</span><strong>{formatPeso(selected.deduction_per_payroll)}</strong></div>
          <div><span>Purpose</span><strong>{selected.purpose || '—'}</strong></div>
          <div className="form-span-2"><span>Notes</span><strong>{selected.notes || '—'}</strong></div>
        </div>
        <SupportingDocumentList entityType="CASH_ADVANCE" entityId={selected.id} />
        <div className="section-heading-row cash-advance-ledger-heading"><div><h3>Payments & Activity History</h3><p>Manual repayments, payroll deductions, issuance, and write-off activity for this cash advance.</p></div></div>
        <div className="table-wrap"><table className="data-table cash-advance-ledger-table"><thead><tr><th>Date</th><th>Activity</th><th>Amount</th><th>Processed / Approved By</th><th>Notes / Reference</th></tr></thead><tbody>{(selected.ledger || []).map((entry) => <tr key={entry.id}><td>{formatDisplayDate(entry.entry_date)}</td><td><strong>{ledgerLabel(entry.entry_type)}</strong></td><td>{formatPeso(Math.abs(Number(entry.amount || 0)))}</td><td>{entry.processed_by || '—'}</td><td>{entry.notes || '—'}{entry.payroll_run_id ? <span className="table-subline">Payroll Run #{entry.payroll_run_id}</span> : null}</td></tr>)}{!(selected.ledger || []).length ? <tr><td colSpan="5"><div className="empty-state">No ledger activity yet.</div></td></tr> : null}</tbody></table></div>
        <div className="modal-actions"><button type="button" className="secondary-button" onClick={() => setSelected(null)}>Close</button></div>
      </div>
    </Modal> : null}
  </div>
}
