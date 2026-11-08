import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import SearchableSelect from '../../components/common/SearchableSelect'
import Modal from '../../components/common/Modal'
import { formatPeso } from '../../utils/formatters'
import './accounting-expense.css'

const localDate = () => {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
export default function DisbursementsPage() {
  const [filters, setFilters] = useState({ from: `${localDate().slice(0,8)}01`, to: localDate(), source: 'ALL' })
  const [data, setData] = useState({ rows: [], summary: {} })
  const [selected, setSelected] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const load = useCallback(async () => {
    setLoading(true)
    try { const response = await api.get('/v1/accounting/disbursements', { params: filters }); setData(response.data); setError('') }
    catch (e) { setError(getApiErrorMessage(e, 'Unable to load disbursements.')) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [])
  const label = (row) => row.source_label || ({ EXPENSE: row.source_event === 'PAYMENT' ? 'Manual Entry (AP Expense)' : 'Manual Entry', INVENTORY_RECEIPT: 'Stock Received (AP Expense)', PAYROLL_RUN: 'Payroll', STAFF_TIP: 'Tips Release', EMPLOYEE_CASH_ADVANCE_LEDGER: 'Cash Advance' }[row.source_type] || row.source_type)
  const displayDate = (iso) => {
    if (!iso) return '—'
    const [year, month, day] = iso.slice(0, 10).split('-').map(Number)
    if (!year || !month || !day) return iso
    const date = new Date(year, month - 1, day)
    const shortMonth = date.toLocaleString('en-US', { month: 'short' })
    return `${shortMonth}. ${day}, ${year}`
  }
  return <div className="page-stack accounting-page">
    <section className="page-heading live-page-heading"><div><h1 className="live-page-title">Disbursements <PageRefreshButton label="Refresh disbursements" onRefresh={load} /></h1><p>Actual outgoing payments recorded in posted journals. Outstanding payables remain in Accounts Payable.</p></div></section>
    {error ? <div className="form-alert error">{error}</div> : null}
    <section className="cash-flow-card-grid"><article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Total Disbursed</strong><div className="cash-balance-amount">{formatPeso(data.summary?.total_disbursed || 0)}</div></article><article className="cash-balance-card cash-flow-card"><strong className="cash-balance-name cash-flow-card-label">Posted Disbursement Events</strong><div className="cash-balance-amount">{data.summary?.count || 0}</div></article></section>
    <section className="content-card"><div className="accounting-ops-filters">
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} /></label>
      <label className="form-field"><span>Source</span><SearchableSelect value={filters.source} onChange={(e) => setFilters((f) => ({ ...f, source: e.target.value }))}><option value="ALL">All payment types</option><option value="MANUAL_ENTRY">Manual Entry</option><option value="MANUAL_AP">Manual Entry (AP Expense)</option><option value="STOCK_RECEIVED_AP">Stock Received (AP Expense)</option><option value="PAYROLL_RUN">Payroll</option><option value="STAFF_TIP">Tips Release</option><option value="EMPLOYEE_CASH_ADVANCE_LEDGER">Cash Advance</option></SearchableSelect></label>
      <button className="secondary-button accounting-filter-button" type="button" onClick={load} disabled={loading}>Apply</button>
    </div></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Journal No.</th><th>Source</th><th>Source Ref No.</th><th>Branch</th><th>Funding Source</th><th>Amount</th><th>Action</th></tr></thead><tbody>
      {data.rows.map((r) => <tr key={r.journal_id}><td><strong>{r.journal_code}</strong><span className="table-subline">{displayDate(r.date)}</span></td><td>{label(r)}</td><td>{r.source_reference || '—'}</td><td>{r.branch || '—'}</td><td>{r.funding_sources.map((f) => <div key={f.account_id}>{f.account}</div>)}</td><td><strong>{formatPeso(r.amount)}</strong></td><td><button type="button" className="secondary-button compact-button" onClick={() => setSelected(r)}>View Journal</button></td></tr>)}
      {!data.rows.length ? <tr><td colSpan="7" className="empty-table-cell">No posted disbursements match this period and source.</td></tr> : null}
    </tbody></table></div></section>
    {selected ? <Modal wide title={selected.journal_code} subtitle={`${label(selected)} · ${selected.source_reference || 'No source reference'}`} onClose={() => setSelected(null)}>
      <p>{selected.description}</p><div className="table-wrap"><table className="data-table"><thead><tr><th>COA</th><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>{selected.coa_lines.map((line, index) => <tr key={index}><td>{line.account_code}</td><td>{line.account}</td><td>{formatPeso(line.debit)}</td><td>{formatPeso(line.credit)}</td></tr>)}</tbody></table></div>
      <div className="modal-actions"><button className="secondary-button" onClick={() => setSelected(null)}>Close</button></div>
    </Modal> : null}
  </div>
}
