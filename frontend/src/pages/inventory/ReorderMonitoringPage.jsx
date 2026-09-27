import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatPeso } from '../../utils/formatters'
import './inventory.css'

export default function ReorderMonitoringPage() {
  const [filters, setFilters] = useState({ branch_id: '', search: '' })
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const load = useCallback(async (next = filters) => { const r = await api.get('/v1/inventory/reorder',{params:{...next,branch_id:next.branch_id||undefined}}); setData(r.data); if(!next.branch_id&&r.data.branch_id)setFilters(c=>({...c,branch_id:String(r.data.branch_id)})) }, [filters])
  useEffect(()=>{load().catch(e=>setMessage(getApiErrorMessage(e,'Unable to load reorder monitoring.'))).finally(()=>setLoading(false))},[])
  async function apply(e){e?.preventDefault();setLoading(true);try{await load(filters)}catch(err){setMessage(getApiErrorMessage(err,'Unable to load reorder monitoring.'))}finally{setLoading(false)}}
  async function reset(){const next={branch_id:filters.branch_id,search:''};setFilters(next);setLoading(true);try{await load(next)}finally{setLoading(false)}}
  return <div className="page-stack"><div className="page-heading"><div><h1 className="live-page-title">Reorder Monitoring <PageRefreshButton label="Refresh reorder monitoring" disabled={loading} onRefresh={()=>load(filters)} /></h1><p>SKUs at or below their configured reorder point.</p></div></div>{message?<div className="form-alert error">{message}</div>:null}<section className="content-card"><form className="inventory-filter-grid" onSubmit={apply}><label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={e=>setFilters(c=>({...c,branch_id:e.target.value}))}>{(data?.branches||[]).map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><label className="form-field"><span>Search</span><input value={filters.search} onChange={e=>setFilters(c=>({...c,search:e.target.value}))} placeholder="SKU or product" /></label><div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div></form></section><section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Product</th><th>Category</th><th>Available</th><th>Reorder Point</th><th>Shortfall</th><th>Unit Cost</th><th>Shortfall Cost</th></tr></thead><tbody>{(data?.rows||[]).map(row=><tr key={row.product_id}><td><strong>{row.name}</strong><span className="table-subline">{row.sku}</span></td><td>{row.category||'—'}</td><td><strong>{row.available}</strong> {row.unit}</td><td>{row.reorder_point}</td><td><span className="status-pill warning">{row.shortfall_to_reorder_point}</span></td><td>{formatPeso(row.unit_cost)}</td><td>{formatPeso(Number(row.shortfall_to_reorder_point||0)*Number(row.unit_cost||0))}</td></tr>)}{!(data?.rows||[]).length?<tr><td colSpan="7"><div className="empty-state">No SKUs are currently at or below reorder point.</div></td></tr>:null}</tbody></table></div></section></div>
}
