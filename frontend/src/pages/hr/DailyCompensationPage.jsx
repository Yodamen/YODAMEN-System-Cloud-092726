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

export default function DailyCompensationPage() {
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), personnel_id: '' })
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [rebuilding, setRebuilding] = useState(false)
  const [message, setMessage] = useState('')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/hr/daily-compensation', { params: next })
    setData(response.data)
  }, [filters])

  useEffect(() => {
    load(filters).catch((error)=>setMessage(getApiErrorMessage(error,'Unable to load Daily Compensation.'))).finally(()=>setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function apply(event) {
    event.preventDefault(); setLoading(true); setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error,'Unable to load Daily Compensation.')) } finally { setLoading(false) }
  }

  async function resetFilters() {
    const next = { from: monthStart(), to: today(), personnel_id: '' }
    setFilters(next); setLoading(true); setMessage('')
    try { await load(next) } catch (error) { setMessage(getApiErrorMessage(error,'Unable to load Daily Compensation.')) } finally { setLoading(false) }
  }

  async function rebuild() {
    setRebuilding(true); setMessage('')
    try {
      const response = await api.post('/v1/hr/daily-compensation/rebuild', {
        from: filters.from,
        to: filters.to,
        personnel_id: filters.personnel_id ? Number(filters.personnel_id) : null,
      })
      setData(response.data)
    } catch (error) { setMessage(getApiErrorMessage(error,'Unable to recalculate Daily Compensation.')) } finally { setRebuilding(false) }
  }

  return (
    <div className="page-stack">
      <div className="page-heading"><div><p className="eyebrow">HR & Payroll</p><h1 className="live-page-title">Daily Compensation <PageRefreshButton label="Refresh daily compensation" disabled={loading || rebuilding} onRefresh={() => load(filters)} /></h1><p>Attendance, paid leave, confirmed commission, and guaranteed-pay rules combined into payroll-ready daily snapshots.</p></div><div className="toolbar-row"><button className="primary-button" type="button" onClick={rebuild} disabled={rebuilding}>{rebuilding?'Recalculating...':'Recalculate'}</button></div></div>
      {message ? <div className="form-alert error">{message}</div> : null}
      <section className="daily-comp-summary-layout">
        <div className="daily-comp-summary-left">
          <article className="metric-card"><span>Hourly Basic</span><strong>{formatPeso(data?.totals?.base_pay)}</strong></article>
          <article className="metric-card"><span>OT Pay</span><strong>{formatPeso(data?.totals?.ot_pay)}</strong></article>
          <article className="metric-card"><span>Commission</span><strong>{formatPeso(data?.totals?.commission)}</strong></article>
          <article className="metric-card"><span>Guaranteed Top-Up</span><strong>{formatPeso(data?.totals?.guaranteed_top_up)}</strong></article>
        </div>
        <article className="metric-card daily-comp-projected-gross"><span>Projected Gross</span><strong>{formatPeso(data?.totals?.gross)}</strong></article>
      </section>
      <section className="content-card"><form className="attendance-filter-grid" onSubmit={apply}>
        <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e)=>setFilters((c)=>({...c,from:e.target.value}))}/></label>
        <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e)=>setFilters((c)=>({...c,to:e.target.value}))}/></label>
        <label className="form-field"><span>Personnel</span><SearchableSelect value={filters.personnel_id} onChange={(e)=>setFilters((c)=>({...c,personnel_id:e.target.value}))}><option value="">All Personnel</option>{(data?.personnel||[]).map((row)=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <div className="attendance-filter-actions"><button className="primary-button" type="submit">Apply</button><button className="secondary-button" type="button" onClick={resetFilters}>Reset</button></div>
      </form></section>
      <section className="content-card">
        {loading?<div className="empty-state">Loading compensation...</div>:null}
        {!loading && !(data?.rows||[]).length?<div className="empty-state">No daily compensation rows found. Use Recalculate after attendance, leave, or commission activity exists.</div>:null}
        {(data?.rows||[]).length?<div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Personnel</th><th>Rule</th><th>Hours</th><th>Leave</th><th>Basic</th><th>OT</th><th>Commission</th><th>Top-Up</th><th>Gross</th><th>Status</th></tr></thead><tbody>{data.rows.map((row)=><tr key={row.id}><td>{formatDisplayDate(row.work_date)}</td><td><strong>{row.personnel?.name}</strong><span className="table-subline">{row.personnel?.code}</span></td><td>{row.compensation_rule.replaceAll('_',' ')}</td><td>{Number(row.worked_hours).toFixed(2)} / {Number(row.scheduled_hours).toFixed(2)}<span className="table-subline">OT {Number(row.ot_hours).toFixed(2)}h</span></td><td>{row.leave_type||'—'}{Number(row.paid_leave_units)>0?<span className="table-subline">{Number(row.paid_leave_units).toFixed(2)} day</span>:null}</td><td>{formatPeso(row.hourly_basic_pay)}</td><td>{formatPeso(row.ot_pay)}</td><td>{formatPeso(row.confirmed_commission)}</td><td>{formatPeso(row.guaranteed_top_up)}</td><td><strong>{formatPeso(row.gross_daily_projection)}</strong></td><td><span className="status-pill neutral">{row.status.replaceAll('_',' ')}</span></td></tr>)}</tbody></table></div>:null}
      </section>
    </div>
  )
}
