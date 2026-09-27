import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatPeso } from '../../utils/formatters'
import './accounting-operations.css'
import '../expense/accounting-expense.css'

const localDateIso = (date = new Date()) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const today = () => localDateIso()
const monthStart = () => `${today().slice(0, 8)}01`
const peso = (v) => formatPeso(Number(v || 0))
const dateLabel = (v) => v ? new Date(v).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—'
const badgeTone = (status) => ['PAID','RECONCILED','APPROVED'].includes(status) ? 'success' : ['REVERSED','CANCELLED','REJECTED'].includes(status) ? 'danger' : ['DRAFT','OPEN'].includes(status) ? 'warning' : 'neutral'

function Heading({ title, subtitle, onRefresh, action }) {
  return <section className="page-heading live-page-heading"><div><h1 className="live-page-title">{title} <PageRefreshButton label={`Refresh ${title}`} onRefresh={onRefresh} /></h1><p>{subtitle}</p></div>{action}</section>
}

function BranchFilter({ options, value, onChange, children }) {
  return <section className="content-card"><div className="accounting-ops-filters">{children}<label className="form-field"><span>Branch</span><SearchableSelect value={value} onChange={(e) => onChange(e.target.value)}><option value="">All Branches</option>{(options?.branches || []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</SearchableSelect></label></div></section>
}

export function AccountsReceivablePage() {
  const [filters, setFilters] = useState({ as_of: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const [message, setMessage] = useState('')
  const load = useCallback(async () => { try { const r = await api.get('/v1/accounting/accounts-receivable', { params: { ...filters, branch_id: filters.branch_id || undefined } }); setData(r.data); setMessage('') } catch (e) { setMessage(getApiErrorMessage(e)) } }, [filters])
  useEffect(() => { load() }, [])
  return <div className="page-stack accounting-page"><Heading title="Accounts Receivable" subtitle="Outstanding finalized customer balances with aging from the financial finalization date." onRefresh={load} />{message ? <div className="form-alert error">{message}</div> : null}
    <section className="cash-flow-card-grid accounting-ar-summary-cards">
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Total Receivable</strong><div className="cash-balance-amount">{peso(data.summary?.total)}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Current</strong><div className="cash-balance-amount">{peso(data.summary?.current)}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">1–30 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['1_30'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">31–60 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['31_60'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">61–90 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['61_90'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Over 90 Days</strong><div className="cash-balance-amount">{peso(data.summary?.over_90)}</div></article>
    </section>
    <BranchFilter options={data.options} value={filters.branch_id} onChange={(v) => setFilters((c) => ({ ...c, branch_id: v }))}><label className="form-field"><span>As Of</span><input type="date" value={filters.as_of} onChange={(e) => setFilters((c) => ({ ...c, as_of: e.target.value }))} /></label><button className="secondary-button accounting-filter-button" onClick={load}>Apply</button></BranchFilter>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Sale / Booking</th><th>Customer</th><th>Branch</th><th>Finalized</th><th>Net</th><th>Applied</th><th>Outstanding</th><th>Aging</th></tr></thead><tbody>{data.rows.map((r) => <tr key={r.id}><td><strong>{r.sale_code}</strong><span className="table-subline">{r.booking_code || 'Direct Sale'}</span></td><td>{r.customer}</td><td>{r.branch || '—'}</td><td>{dateLabel(r.finalized_at)}</td><td>{peso(r.net_amount)}</td><td>{peso(r.applied_amount)}</td><td><strong>{peso(r.outstanding_balance)}</strong></td><td><span className={`status-pill ${r.aging_bucket === 'CURRENT' ? 'success' : r.aging_bucket === 'OVER_90' ? 'danger' : 'warning'}`}>{r.aging_bucket.replace('_',' ')}</span><span className="table-subline">{r.age_days} days</span></td></tr>)}{!data.rows.length ? <tr><td colSpan="8"><div className="empty-state">No outstanding receivables.</div></td></tr> : null}</tbody></table></div></section>
  </div>
}


export function AccountsPayablePage() {
  const [filters, setFilters] = useState({ as_of: today(), branch_id: '', source: 'ALL', search: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const [paying, setPaying] = useState(null)
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    try {
      const response = await api.get('/v1/accounting/accounts-payable', { params: { ...filters, branch_id: filters.branch_id || undefined, search: filters.search || undefined } })
      setData(response.data)
      setMessage('')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load accounts payable.'))
    }
  }, [filters])

  useEffect(() => { load() }, [])

  const openPayment = (row) => {
    setPaying({ ...row, payment_splits: [{ funding_account_id: data.options?.funding_sources?.[0]?.id ? String(data.options.funding_sources[0].id) : '', amount: String(row.outstanding_amount || ''), reference: '' }] })
  }

  const patchSplit = (index, patch) => setPaying((current) => ({ ...current, payment_splits: current.payment_splits.map((row, i) => i === index ? { ...row, ...patch } : row) }))
  const splitTotal = (paying?.payment_splits || []).reduce((sum, split) => sum + Number(split.amount || 0), 0)

  const pay = async (event) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const sourcePath = paying.source_type === 'INVENTORY_RECEIPT' ? 'inventory-receipt' : 'expense'
      await api.post(`/v1/accounting/accounts-payable/${sourcePath}/${paying.source_id}/pay`, {
        payment_splits: paying.payment_splits.map((split) => ({ funding_account_id: Number(split.funding_account_id), amount: Number(split.amount), reference: split.reference || null })),
      })
      setPaying(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to settle accounts payable.'))
    } finally {
      setSaving(false)
    }
  }

  const reconciliationTone = data.summary?.reconciliation_status === 'BALANCED' ? 'success' : data.summary?.reconciliation_status === 'FILTERED_VIEW' ? 'neutral' : 'warning'

  return <div className="page-stack accounting-page">
    <Heading title="Accounts Payable" subtitle="Outstanding supplier and expense obligations posted to Accounts Payable, with aging and settlement through business funding sources." onRefresh={load} />
    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="cash-flow-card-grid accounting-ap-summary-cards">
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Total Operational Payables</strong><div className="cash-balance-amount">{peso(data.summary?.total)}</div></article>
      <article className="cash-balance-card cash-flow-card"><div className="metric-label-row"><strong className="cash-balance-name cash-flow-card-label">AP Ledger Balance</strong><span className={`ap-ledger-badge ${reconciliationTone}`}>{data.summary?.reconciliation_status === 'BALANCED' ? 'Balanced' : data.summary?.reconciliation_status === 'FILTERED_VIEW' ? 'Filtered' : 'Review'}</span></div><div className="cash-balance-amount">{peso(data.summary?.ledger_balance)}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Current</strong><div className="cash-balance-amount">{peso(data.summary?.current)}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">1–30 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['1_30'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">31–60 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['31_60'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">61–90 Days</strong><div className="cash-balance-amount">{peso(data.summary?.['61_90'])}</div></article>
      <article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Over 90 Days</strong><div className="cash-balance-amount">{peso(data.summary?.over_90)}</div></article>
    </section>

    {data.summary?.reconciliation_status === 'NEEDS_REVIEW' ? <div className="form-alert warning">Operational payable documents differ from the Accounts Payable ledger by <strong>{peso(data.summary?.variance)}</strong>. Review General Ledger for manual or historical AP postings.</div> : null}

    <section className="content-card"><div className="accounting-ops-filters">
      <label className="form-field"><span>As Of</span><input type="date" value={filters.as_of} onChange={(e) => setFilters((c) => ({ ...c, as_of: e.target.value }))} /></label>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All Branches</option>{(data.options?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Source</span><SearchableSelect value={filters.source} onChange={(e) => setFilters((c) => ({ ...c, source: e.target.value }))}>{(data.options?.sources || []).map((source) => <option key={source.code} value={source.code}>{source.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Search</span><input value={filters.search} onChange={(e) => setFilters((c) => ({ ...c, search: e.target.value }))} placeholder="Document, payee, reference" /></label>
      <button className="secondary-button accounting-filter-button" type="button" onClick={load}>Apply</button>
    </div></section>

    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Document</th><th>Type</th><th>Payee / Supplier</th><th>Branch</th><th>Date</th><th>Reference</th><th>Amount Due</th><th>Aging</th><th>Action</th></tr></thead><tbody>
      {(data.rows || []).map((row) => <tr key={`${row.source_type}-${row.source_id}`}><td><strong>{row.code}</strong></td><td>{row.source_type === 'INVENTORY_RECEIPT' ? 'Stock Received' : 'Expense'}</td><td>{row.payee}</td><td>{row.branch || '—'}</td><td>{dateLabel(row.document_date)}</td><td>{row.reference || '—'}</td><td><strong>{peso(row.outstanding_amount)}</strong></td><td><span className={`status-pill ${row.aging_bucket === 'CURRENT' ? 'success' : row.aging_bucket === 'OVER_90' ? 'danger' : 'warning'}`}>{row.aging_bucket.replace('_',' ')}</span><span className="table-subline">{row.age_days} days</span></td><td><button className="primary-button compact-button" type="button" onClick={() => openPayment(row)}>Pay</button></td></tr>)}
      {!(data.rows || []).length ? <tr><td colSpan="9"><div className="empty-state">No outstanding accounts payable.</div></td></tr> : null}
    </tbody></table></div></section>

    {paying ? <Modal wide title={`Pay ${paying.code}`} subtitle={`Settle ${peso(paying.outstanding_amount)} using one or more business funding sources.`} onClose={() => setPaying(null)}><form onSubmit={pay}>
      {!(data.options?.funding_sources || []).length ? <div className="form-alert warning">No Expense / Supplier Funding Sources are active. Enable one in Chart of Accounts first.</div> : null}
      <div className="ap-payment-splits">{paying.payment_splits.map((split, index) => <div className="ap-payment-split-row" key={index}>
        <label className="form-field"><span>Funding Source</span><SearchableSelect required value={split.funding_account_id} onChange={(e) => patchSplit(index, { funding_account_id: e.target.value })}><option value="">Select source</option>{(data.options?.funding_sources || []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Amount</span><input required type="number" min="0.01" step="0.01" value={split.amount} onChange={(e) => patchSplit(index, { amount: e.target.value })} /></label>
        <label className="form-field"><span>Reference</span><input value={split.reference} onChange={(e) => patchSplit(index, { reference: e.target.value })} placeholder="Optional" /></label>
        <button className="secondary-button compact-button" type="button" disabled={paying.payment_splits.length === 1} onClick={() => setPaying((current) => ({ ...current, payment_splits: current.payment_splits.filter((_, i) => i !== index) }))}>Remove</button>
      </div>)}</div>
      <div className="ap-payment-summary"><span>Payment Total</span><strong>{peso(splitTotal)}</strong><span>Amount Due</span><strong>{peso(paying.outstanding_amount)}</strong></div>
      <div className="accounting-ops-section-heading"><div></div><button className="secondary-button" type="button" onClick={() => setPaying((current) => ({ ...current, payment_splits: [...current.payment_splits, { funding_account_id: '', amount: '', reference: '' }] }))}>+ Add Funding Source</button></div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setPaying(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving || !(data.options?.funding_sources || []).length}>Pay Accounts Payable</button></div>
    </form></Modal> : null}
  </div>
}

export function CollectionsPage() {
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), branch_id: '' })
  const [data, setData] = useState({ rows: [], summary: {}, options: {} })
  const load = useCallback(async () => { const r = await api.get('/v1/accounting/collections', { params: { ...filters, branch_id: filters.branch_id || undefined } }); setData(r.data) }, [filters])
  useEffect(() => { load() }, [])
  return <div className="page-stack accounting-page"><Heading title="Collections" subtitle="Cash-receipt ledger with application and unapplied balances. Original receipts remain preserved even when later reversed." onRefresh={load} />
    <section className="metric-grid accounting-ops-metrics"><article className="metric-card"><span>Cash Received</span><strong>{peso(data.summary?.cash_received)}</strong></article><article className="metric-card"><span>Applied</span><strong>{peso(data.summary?.applied)}</strong></article><article className="metric-card"><span>Unapplied</span><strong>{peso(data.summary?.unapplied)}</strong></article><article className="metric-card"><span>Reversed Receipts</span><strong>{peso(data.summary?.reversed)}</strong></article></section>
    <BranchFilter options={data.options} value={filters.branch_id} onChange={(v) => setFilters((c) => ({ ...c, branch_id: v }))}><label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label><label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label><button className="secondary-button accounting-filter-button" onClick={load}>Apply</button></BranchFilter>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Payment</th><th>Customer</th><th>Sale / Booking</th><th>Type</th><th>Method</th><th>Received</th><th>Applied</th><th>Unapplied</th><th>Status</th></tr></thead><tbody>{data.rows.map((r) => <tr key={r.id}><td>{dateLabel(r.date)}</td><td><strong>{r.code}</strong><span className="table-subline">{r.reference || 'No reference'}</span></td><td>{r.customer}</td><td>{r.sale_code || '—'}<span className="table-subline">{r.booking_code || 'Direct'}</span></td><td>{r.type}</td><td>{r.method || '—'}</td><td>{peso(r.amount)}</td><td>{peso(r.applied)}</td><td>{peso(r.unapplied)}</td><td><span className={`status-pill ${badgeTone(r.status)}`}>{r.status}</span></td></tr>)}</tbody></table></div></section>
  </div>
}

function blankRecon(options) { return { branch_id: options?.branches?.[0]?.id || '', bank_account_id: options?.bank_accounts?.[0]?.id || '', statement_date: today(), statement_ending_balance: '', notes: '', items: [] } }
function blankReconItem() { return { item_type: 'OUTSTANDING', effect_on: 'BANK', description: '', reference: '', amount: '', adjustment_account_id: '' } }

export function BankReconciliationPage() {
  const [data, setData] = useState({ rows: [], options: {} })
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const load = useCallback(async () => { const r = await api.get('/v1/accounting/bank-reconciliation'); setData(r.data) }, [])
  useEffect(() => { load() }, [])
  const save = async (e) => { e.preventDefault(); setSaving(true); setMessage(''); try { const payload = { ...form, branch_id: form.branch_id || null, bank_account_id: Number(form.bank_account_id), statement_ending_balance: Number(form.statement_ending_balance), items: form.items.map((i) => ({ ...i, amount: Number(i.amount), adjustment_account_id: i.adjustment_account_id ? Number(i.adjustment_account_id) : null })) }; if (form.id) await api.put(`/v1/accounting/bank-reconciliation/${form.id}`, payload); else await api.post('/v1/accounting/bank-reconciliation', payload); setForm(null); await load() } catch (err) { setMessage(getApiErrorMessage(err)) } finally { setSaving(false) } }
  const reconcile = async (r) => { try { await api.post(`/v1/accounting/bank-reconciliation/${r.id}/reconcile`); await load() } catch (e) { setMessage(getApiErrorMessage(e)) } }
  return <div className="page-stack accounting-page"><Heading title="Bank Reconciliation" subtitle="Match bank statements to the accounting ledger after deposits and other bank activity are recorded. Record physical cash movement under Sales → Cash Handling first." onRefresh={load} action={<button className="primary-button" onClick={() => setForm(blankRecon(data.options))}>New Reconciliation</button>} />{message ? <div className="form-alert error">{message}</div> : null}
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Code</th><th>Statement Date</th><th>Bank Account</th><th>Statement</th><th>Book</th><th>Adjusted Bank</th><th>Adjusted Book</th><th>Difference</th><th>Status</th><th>Action</th></tr></thead><tbody>{data.rows.map((r) => <tr key={r.id}><td><strong>{r.code}</strong><span className="table-subline">{r.branch?.name || 'All Branches'}</span></td><td>{dateLabel(r.statement_date)}</td><td>{r.bank_account?.code} · {r.bank_account?.name}</td><td>{peso(r.statement_ending_balance)}</td><td>{peso(r.book_balance_snapshot)}</td><td>{peso(r.adjusted_bank_balance)}</td><td>{peso(r.adjusted_book_balance)}</td><td><strong>{peso(r.difference)}</strong></td><td><span className={`status-pill ${badgeTone(r.status)}`}>{r.status}</span></td><td>{r.status === 'DRAFT' ? <div className="inline-actions"><button className="secondary-button compact-button" onClick={() => setForm({ ...r, bank_account_id: r.bank_account_id, branch_id: r.branch_id || '', items: (r.items || []).map((i) => ({ ...i, adjustment_account_id: i.adjustment_account_id || '' })) })}>Edit</button><button className="primary-button compact-button" disabled={Math.abs(Number(r.difference)) > .009} onClick={() => reconcile(r)}>Reconcile</button></div> : '—'}</td></tr>)}</tbody></table></div></section>
    {form ? <Modal wide className="accounting-ops-wide-modal" title={form.id ? `Edit ${form.code}` : 'New Bank Reconciliation'} subtitle="Bank-side items reconcile timing differences. Book-side items post accounting adjustments when finalized." onClose={() => setForm(null)}><form onSubmit={save}><div className="form-grid"><label className="form-field"><span>Branch</span><SearchableSelect value={form.branch_id || ''} onChange={(e) => setForm((c) => ({ ...c, branch_id: e.target.value }))}><option value="">All Branches</option>{(data.options?.branches || []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Bank Account</span><SearchableSelect required value={form.bank_account_id} onChange={(e) => setForm((c) => ({ ...c, bank_account_id: e.target.value }))}>{(data.options?.bank_accounts || []).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Statement Date</span><input required type="date" value={form.statement_date?.slice?.(0,10) || form.statement_date} onChange={(e) => setForm((c) => ({ ...c, statement_date: e.target.value }))} /></label><label className="form-field"><span>Statement Ending Balance</span><input required type="number" step="0.01" value={form.statement_ending_balance} onChange={(e) => setForm((c) => ({ ...c, statement_ending_balance: e.target.value }))} /></label></div><div className="accounting-ops-section-heading"><h3>Reconciling Items</h3><button className="secondary-button compact-button" type="button" onClick={() => setForm((c) => ({ ...c, items: [...c.items, blankReconItem()] }))}>Add Item</button></div><div className="accounting-recon-lines"><div className="accounting-recon-header"><span>Effect</span><span>Type</span><span>Description</span><span>Reference</span><span>Amount (+ / -)</span><span>Adjustment Account</span><span></span></div>{form.items.map((i, idx) => <div className="accounting-recon-line" key={idx}><SearchableSelect value={i.effect_on} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, effect_on:e.target.value } : x) }))}><option>BANK</option><option>BOOK</option></SearchableSelect><SearchableSelect value={i.item_type} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, item_type:e.target.value } : x) }))}><option value="OUTSTANDING">Outstanding</option><option value="DEPOSIT_IN_TRANSIT">Deposit in Transit</option><option value="BANK_CHARGE">Bank Charge</option><option value="INTEREST">Interest</option><option value="OTHER">Other</option></SearchableSelect><input required value={i.description} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, description:e.target.value } : x) }))} /><input value={i.reference || ''} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, reference:e.target.value } : x) }))} /><input required type="number" step="0.01" value={i.amount} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, amount:e.target.value } : x) }))} /><SearchableSelect disabled={i.effect_on !== 'BOOK'} value={i.adjustment_account_id || ''} onChange={(e) => setForm((c) => ({ ...c, items: c.items.map((x,n) => n===idx ? { ...x, adjustment_account_id:e.target.value } : x) }))}><option value="">Select account</option>{(data.options?.adjustment_accounts || []).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</SearchableSelect><button type="button" className="secondary-button compact-button" onClick={() => setForm((c) => ({ ...c, items: c.items.filter((_,n) => n!==idx) }))}>Remove</button></div>)}</div><label className="form-field"><span>Notes</span><textarea rows="3" value={form.notes || ''} onChange={(e) => setForm((c) => ({ ...c, notes:e.target.value }))} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving ? 'Saving...' : 'Save Draft'}</button></div></form></Modal> : null}
  </div>
}

export function ProfitSharingPage() {
  const [data, setData] = useState({ rows: [], options: {} })
  const [form, setForm] = useState(null)
  const [paying, setPaying] = useState(null)
  const [message, setMessage] = useState('')
  const load = useCallback(async () => { const r = await api.get('/v1/accounting/profit-sharing'); setData(r.data) }, [])
  useEffect(() => { load() }, [])
  const create = async (e) => { e.preventDefault(); try { await api.post('/v1/accounting/profit-sharing', { ...form, branch_id: form.branch_id || null, distributable_profit: form.distributable_profit === '' ? null : Number(form.distributable_profit) }); setForm(null); await load() } catch (err) { setMessage(getApiErrorMessage(err)) } }
  const action = async (r, action, payload={}) => { try { await api.post(`/v1/accounting/profit-sharing/${r.id}/${action}`, payload); await load() } catch (e) { setMessage(getApiErrorMessage(e)) } }
  return <div className="page-stack accounting-page"><Heading title="Profit Sharing" subtitle="Partner percentages are snapshotted using the percentage effective on Period End. Drafts can recalculate; Approved and Paid runs are frozen." onRefresh={load} action={<button className="primary-button" onClick={() => setForm({ branch_id:'', period_start:monthStart(), period_end:today(), basis:'ACCRUAL', cost_basis:'AUDIT_ADJUSTED', distributable_profit:'', notes:'' })}>New Run</button>} />{message ? <div className="form-alert error">{message}</div> : null}<section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Run</th><th>Period</th><th>Basis</th><th>Net Income</th><th>Distributable</th><th>Total %</th><th>Profit Share</th><th>Status</th><th>Action</th></tr></thead><tbody>{data.rows.map((r) => <tr key={r.id}><td><strong>{r.code}</strong><span className="table-subline">{r.branch?.name || 'All Branches'}</span></td><td>{dateLabel(r.period_start)} – {dateLabel(r.period_end)}</td><td>{r.basis}<span className="table-subline">{r.cost_basis.replaceAll('_',' ')}</span></td><td>{peso(r.net_income_snapshot)}</td><td>{peso(r.distributable_profit)}</td><td>{Number(r.total_percentage).toFixed(2)}%</td><td><strong>{peso(r.total_profit_share)}</strong></td><td><span className={`status-pill ${badgeTone(r.status)}`}>{r.status}</span></td><td><div className="inline-actions">{r.status === 'DRAFT' ? <><button className="secondary-button compact-button" onClick={() => action(r,'recalculate')}>Recalculate</button><button className="primary-button compact-button" onClick={() => action(r,'approve')}>Approve</button></> : null}{r.status === 'APPROVED' ? <button className="primary-button compact-button" onClick={() => setPaying({ id:r.id, payment_account_id:data.options?.cash_accounts?.[0]?.id || '' })}>Mark Paid</button> : null}</div></td></tr>)}</tbody></table></div></section>
    {form ? <Modal title="New Profit Sharing Run" subtitle="Leave Distributable Profit blank to use positive Net Income from the selected accounting view." onClose={() => setForm(null)}><form onSubmit={create}><div className="form-grid"><label className="form-field"><span>Period Start</span><input required type="date" value={form.period_start} onChange={(e) => setForm((c) => ({ ...c, period_start:e.target.value }))} /></label><label className="form-field"><span>Period End</span><input required type="date" value={form.period_end} onChange={(e) => setForm((c) => ({ ...c, period_end:e.target.value }))} /></label><label className="form-field"><span>Branch</span><SearchableSelect value={form.branch_id} onChange={(e) => setForm((c) => ({ ...c, branch_id:e.target.value }))}><option value="">All Branches</option>{(data.options?.branches || []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Accounting Basis</span><SearchableSelect value={form.basis} onChange={(e) => setForm((c) => ({ ...c, basis:e.target.value }))}><option>ACCRUAL</option><option>CASH</option></SearchableSelect></label><label className="form-field"><span>Cost Basis</span><SearchableSelect value={form.cost_basis} onChange={(e) => setForm((c) => ({ ...c, cost_basis:e.target.value }))}><option value="AUDIT_ADJUSTED">Audit-Adjusted COGS</option><option value="SYSTEM">System COGS</option></SearchableSelect></label><label className="form-field"><span>Distributable Profit</span><input type="number" min="0" step="0.01" placeholder="Use Net Income" value={form.distributable_profit} onChange={(e) => setForm((c) => ({ ...c, distributable_profit:e.target.value }))} /></label></div><label className="form-field"><span>Notes</span><textarea rows="3" value={form.notes} onChange={(e) => setForm((c) => ({ ...c, notes:e.target.value }))} /></label><div className="modal-actions"><button type="button" className="secondary-button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button">Create Draft</button></div></form></Modal> : null}
    {paying ? <Modal title="Pay Profit Sharing" subtitle="This settles Profit Sharing Payable against the selected cash/bank account." onClose={() => setPaying(null)}><form onSubmit={async (e) => { e.preventDefault(); await action({id:paying.id},'pay',{ payment_account_id:Number(paying.payment_account_id) }); setPaying(null) }}><label className="form-field"><span>Payment Account</span><SearchableSelect required value={paying.payment_account_id} onChange={(e) => setPaying((c) => ({ ...c, payment_account_id:e.target.value }))}>{(data.options?.cash_accounts || []).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</SearchableSelect></label><div className="modal-actions"><button type="button" className="secondary-button" onClick={() => setPaying(null)}>Cancel</button><button className="primary-button">Mark Paid</button></div></form></Modal> : null}
  </div>
}

export function MyProfitPage() {
  const [data,setData]=useState({rows:[],summary:{},personnel:null})
  const load=useCallback(async()=>{const r=await api.get('/v1/accounting/my-profit');setData(r.data)},[])
  useEffect(()=>{load()},[])
  return <div className="page-stack accounting-page"><Heading title="My Profit" subtitle="Approved and paid profit sharing linked to your personnel record." onRefresh={load}/><section className="metric-grid accounting-ops-metrics"><article className="metric-card"><span>Approved / Payable</span><strong>{peso(data.summary?.approved)}</strong></article><article className="metric-card"><span>Paid</span><strong>{peso(data.summary?.paid)}</strong></article></section><section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Run</th><th>Period</th><th>Percentage</th><th>Share</th><th>Status</th></tr></thead><tbody>{data.rows.map((r)=><tr key={`${r.code}-${r.period_end}`}><td><strong>{r.code}</strong></td><td>{dateLabel(r.period_start)} – {dateLabel(r.period_end)}</td><td>{Number(r.percentage_snapshot).toFixed(2)}%</td><td><strong>{peso(r.share_amount)}</strong></td><td><span className={`status-pill ${badgeTone(r.status)}`}>{r.status}</span></td></tr>)}</tbody></table></div></section></div>
}

export function AccountingMappingPage() {
  const [data, setData] = useState({ accounts: [], product_mappings: [], payment_mappings: [], summary: {} })
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setMessage('')
    try {
      const response = await api.get('/v1/accounting/mapping')
      setData(response.data)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load accounting mappings.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [])

  const updateProduct = async (row, field, value) => {
    if (!value) return
    try {
      const next = {
        inventory_account_id: row.inventory_account_id,
        service_cost_account_id: row.service_cost_account_id,
        retail_cost_account_id: row.retail_cost_account_id,
        [field]: Number(value),
      }
      await api.put(`/v1/accounting/mapping/products/${row.retail_product_id}`, next)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save inventory accounting mapping.'))
    }
  }

  const updateMethod = async (row, patch = {}) => {
    const cashAccountId = Number(patch.cash_account_id ?? row.cash_account_id ?? 0)
    if (!cashAccountId) return
    try {
      const payload = { cash_account_id: cashAccountId }
      if (Object.prototype.hasOwnProperty.call(patch, 'customer_facing')) payload.customer_facing = Boolean(patch.customer_facing)
      if (Object.prototype.hasOwnProperty.call(patch, 'operating_fund_source')) payload.operating_fund_source = Boolean(patch.operating_fund_source)
      await api.put(`/v1/accounting/mapping/payment-methods/${row.payment_method_reference_id}`, payload)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save payment method accounting mapping.'))
    }
  }

  const options = (filter) => data.accounts.filter(filter).map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)

  return <div className="page-stack accounting-page">
    <Heading title="Accounting Mapping" subtitle="Connect inventory items and payment methods to the accounts used by automatic operational journals." onRefresh={load} />
    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="content-card">
      <div className="section-heading compact-section-heading"><div><h2>Inventory & COGS Mapping</h2><p>Every active inventory item maps to an Inventory account, Service COGS account, and Retail COGS account.</p></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Inventory Account</th><th>Service COGS</th><th>Retail COGS</th></tr></thead><tbody>
        {(data.product_mappings || []).map((row) => <tr key={row.retail_product_id}>
          <td><strong>{row.name}</strong><span className="table-subline">{row.sku}{row.category ? ` · ${row.category}` : ''}</span></td>
          <td><SearchableSelect value={row.inventory_account_id || ''} onChange={(e) => updateProduct(row, 'inventory_account_id', e.target.value)}><option value="">Select</option>{options((account) => account.system_key?.startsWith('INVENTORY_'))}</SearchableSelect></td>
          <td><SearchableSelect value={row.service_cost_account_id || ''} onChange={(e) => updateProduct(row, 'service_cost_account_id', e.target.value)}><option value="">Select</option>{options((account) => account.system_key?.startsWith('COS_SERVICE_'))}</SearchableSelect></td>
          <td><SearchableSelect value={row.retail_cost_account_id || ''} onChange={(e) => updateProduct(row, 'retail_cost_account_id', e.target.value)}><option value="">Select</option>{options((account) => account.system_key === 'COS_RETAIL_PRODUCTS')}</SearchableSelect></td>
        </tr>)}
        {!loading && !(data.product_mappings || []).length ? <tr><td colSpan="4"><div className="empty-state">No active inventory items found. Add products in Inventory Masterlist first.</div></td></tr> : null}
      </tbody></table></div>
    </section>

    <section className="content-card accounting-mapping-card">
      <div className="section-heading compact-section-heading accounting-mapping-heading"><div><h2>Payment Method Mapping</h2><p>Map each cash, bank, e-wallet, or internal payment method to its actual account, then control whether it can be used for customer collections, operating disbursements, or both.</p></div></div>
      <div className="table-wrap accounting-mapping-table-wrap"><table className="data-table accounting-mapping-table">
        <colgroup><col className="mapping-method-col" /><col className="mapping-account-col" /><col className="mapping-toggle-col" /><col className="mapping-toggle-col" /></colgroup>
        <thead><tr><th>Payment Method</th><th>Cash / Clearing Account</th><th className="mapping-toggle-head">Customer Payment</th><th className="mapping-toggle-head">Operating Fund Source</th></tr></thead><tbody>
        {(data.payment_mappings || []).map((row) => {
          const customerLocked = !row.cash_account_id || ['PETTY_CASH','CASH_WITH_MANAGER'].includes(row.cash_account_system_key)
          const operatingLocked = !row.cash_account_id || ['CASH_ON_HAND','CASH_WITH_MANAGER','POS_CLEARING'].includes(row.cash_account_system_key)
          return <tr key={row.payment_method_reference_id}>
            <td><strong>{row.name}</strong><span className="table-subline">{row.code}</span></td>
            <td><SearchableSelect value={row.cash_account_id || ''} onChange={(e) => updateMethod(row, { cash_account_id: e.target.value })}><option value="">Select cash / payment account</option>{options((account) => Boolean(account.is_cash_account) && account.system_key !== 'CASH_WITH_MANAGER')}</SearchableSelect></td>
            <td className="mapping-toggle-cell"><label className="toggle-field compact-toggle accounting-mapping-toggle"><input type="checkbox" checked={Boolean(row.customer_facing) && !customerLocked} disabled={customerLocked} onChange={(e) => updateMethod(row, { customer_facing: e.target.checked })} /><span>{customerLocked ? 'Internal only' : row.customer_facing ? 'Enabled' : 'Disabled'}</span></label></td>
            <td className="mapping-toggle-cell"><label className="toggle-field compact-toggle accounting-mapping-toggle"><input type="checkbox" checked={Boolean(row.operating_fund_source) && !operatingLocked} disabled={operatingLocked} onChange={(e) => updateMethod(row, { operating_fund_source: e.target.checked })} /><span>{operatingLocked ? 'Protected' : row.operating_fund_source ? 'Enabled' : 'Disabled'}</span></label></td>
          </tr>
        })}
        {!loading && !(data.payment_mappings || []).length ? <tr><td colSpan="4"><div className="empty-state">No active Payment Methods found. Add Cash, GCash, bank accounts, Petty Cash, or other methods under Admin → Reference Lists → Payment Method.</div></td></tr> : null}
      </tbody></table></div>
      <div className="mapping-role-note"><span><strong>Customer Payment</strong> controls whether the method appears in Booking, Checkout, and POS.</span><span><strong>Operating Fund Source</strong> controls whether the mapped account can fund Expenses, AP, Payroll, supplier payments, and similar disbursements.</span></div>
    </section>
  </div>
}
