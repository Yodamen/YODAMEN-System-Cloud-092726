import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'
import PageRefreshButton from '../../components/common/PageRefreshButton'

function monthStart() {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-01`
}
function today() {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`
}
function statusClass(status) {
  if (status === 'OPEN') return 'success'
  if (status === 'REVERSED') return 'danger'
  if (['APPROVED','PAID'].includes(status)) return 'success'
  if (status === 'IN_PAYROLL_DRAFT') return 'warning'
  return 'neutral'
}

export default function CommissionSummaryPage() {
  const defaultFilters = { from: monthStart(), to: today(), personnel_id: '', status: 'ALL', booking_code: '', rule_id: '' }
  const [filters, setFilters] = useState(defaultFilters)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/hr/commissions', { params: next })
    setData(response.data)
  }, [filters])

  useEffect(() => {
    load(filters).catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load commission summary.'))).finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function apply(event) {
    event.preventDefault(); setLoading(true); setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load commission summary.')) } finally { setLoading(false) }
  }

  async function resetFilters() {
    setFilters(defaultFilters)
    setLoading(true)
    setMessage('')
    try { await load(defaultFilters) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load commission summary.')) }
    finally { setLoading(false) }
  }

  return (
    <div className="page-stack commission-summary-page">
      <div className="page-heading"><div><p className="eyebrow">HR & Payroll</p><h1 className="live-page-title">Commission Summary <PageRefreshButton label="Refresh commission summary" disabled={loading} onRefresh={() => load(filters)} /></h1><p>Financially confirmed service and retail commissions posted from finalized Sales.</p></div></div>
      {message ? <div className="form-alert error">{message}</div> : null}
      <section className="metric-grid">
        <article className="metric-card"><span>Open Commission</span><strong>{formatPeso(data?.totals?.open)}</strong></article>
        <article className="metric-card"><span>Released Commission</span><strong>{formatPeso(data?.totals?.released)}</strong></article>
        <article className="metric-card"><span>Reversed</span><strong>{formatPeso(data?.totals?.reversed)}</strong></article>
      </section>
      <section className="content-card"><form className="commission-filter-grid" onSubmit={apply}>
        <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({...c, from:e.target.value}))}/></label>
        <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({...c, to:e.target.value}))}/></label>
        <label className="form-field"><span>Booking ID</span><input value={filters.booking_code} onChange={(e)=>setFilters((c)=>({...c,booking_code:e.target.value}))} placeholder="BKG-..." /></label>
        <label className="form-field"><span>Rule</span><SearchableSelect value={filters.rule_id} onChange={(e)=>setFilters((c)=>({...c,rule_id:e.target.value}))}><option value="">All Rules</option>{(data?.rules||[]).map((row)=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Personnel</span><SearchableSelect value={filters.personnel_id} onChange={(e)=>setFilters((c)=>({...c,personnel_id:e.target.value}))}><option value="">All Personnel</option>{(data?.personnel||[]).map((row)=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(e)=>setFilters((c)=>({...c,status:e.target.value}))}><option value="ALL">All Statuses</option><option value="OPEN">Open</option><option value="IN_PAYROLL_DRAFT">In Payroll Draft</option><option value="APPROVED">Approved</option><option value="PAID">Paid</option><option value="REVERSED">Reversed</option></SearchableSelect></label>
        <div className="commission-filter-actions"><button className="primary-button" type="submit">Apply</button><button className="secondary-button" type="button" onClick={resetFilters}>Reset</button></div>
      </form></section>
      <section className="content-card commission-summary-table-card">
        {loading ? <div className="empty-state">Loading commissions...</div> : null}
        {!loading && !(data?.rows||[]).length ? <div className="empty-state">No commission earnings found.</div> : null}
        {(data?.rows||[]).length ? <div className="table-wrap commission-summary-table-wrap"><table className="data-table commission-summary-table">
          <colgroup>
            <col className="commission-col-date" />
            <col className="commission-col-personnel" />
            <col className="commission-col-item" />
            <col className="commission-col-booking" />
            <col className="commission-col-rule" />
            <col className="commission-col-basis" />
            <col className="commission-col-rate" />
            <col className="commission-col-amount" />
            <col className="commission-col-status" />
          </colgroup>
          <thead><tr><th>Date</th><th>Personnel</th><th>Item</th><th>Booking</th><th>Rule</th><th>Basis</th><th>Rate</th><th>Commission</th><th>Status</th></tr></thead>
          <tbody>{data.rows.map((row)=><tr key={row.id}><td>{formatDisplayDate(row.work_date)}</td><td><strong>{row.personnel?.name}</strong><span className="table-subline">{row.personnel?.code}</span></td><td><strong>{row.item?.name || row.service?.name || '—'}</strong><span className="table-subline">{row.earning_type === 'RETAIL' ? 'Retail Purchase' : row.earning_type === 'ADJUSTMENT' ? 'Commission Adjustment' : 'Service'}</span></td><td>{row.booking_code||'—'}</td><td>{row.rule?.name||'—'}<span className="table-subline">{row.rule?.code||''}</span></td><td>{formatPeso(row.basis_amount)}<span className="table-subline">{row.earning_type === 'RETAIL' ? 'Net Retail Sale' : row.earning_type === 'ADJUSTMENT' ? 'Reversal / Correction Adjustment' : 'Service / Rendered Session Value'}</span></td><td>{row.calculation_type==='FIXED'?formatPeso(row.rate_value):`${Number(row.rate_value).toFixed(2)}%`}</td><td><strong>{formatPeso(row.commission_amount)}</strong></td><td><span className={`status-pill ${statusClass(row.status)}`}>{row.status.replaceAll('_',' ')}</span></td></tr>)}</tbody>
        </table></div> : null}
      </section>
    </div>
  )
}
