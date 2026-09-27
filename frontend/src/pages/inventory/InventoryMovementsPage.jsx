import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatPeso } from '../../utils/formatters'
import './inventory.css'

function monthStart() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01` }
function today() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}` }
function dateTime(value) { return value ? new Intl.DateTimeFormat('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(value)) : '—' }

const modeConfig = {
  movements: {
    title: 'Inventory Movements',
    subtitle: 'Append-only stock ledger from retail sales and controlled adjustments.',
    endpoint: '/v1/inventory/movements',
    defaultType: 'ALL',
    showTypeFilter: true,
    refreshLabel: 'Refresh inventory movements',
    emptyLabel: 'No inventory movements found.',
  },
  adjustments: {
    title: 'Inventory Adjustments',
    subtitle: 'Audited stock adjustments with before-and-after balances.',
    endpoint: '/v1/inventory/adjustments',
    defaultType: 'ADJUSTMENT',
    showTypeFilter: false,
    refreshLabel: 'Refresh inventory adjustments',
    emptyLabel: 'No inventory adjustments found.',
  },
  stockReceived: {
    title: 'Stock Received',
    subtitle: 'Inbound stock receipts and receiving history by branch.',
    endpoint: '/v1/inventory/movements',
    defaultType: 'RECEIPT,RECEIVED,STOCK_RECEIVED,PURCHASE_RECEIPT',
    showTypeFilter: false,
    refreshLabel: 'Refresh stock received',
    emptyLabel: 'No stock received entries found.',
  },
  serviceConsumption: {
    title: 'Service Consumption',
    subtitle: 'Inventory usage captured from completed service activity.',
    endpoint: '/v1/inventory/movements',
    defaultType: 'SERVICE_CONSUMPTION,CONSUMPTION',
    showTypeFilter: false,
    refreshLabel: 'Refresh service consumption',
    emptyLabel: 'No service consumption entries found.',
  },
  inventoryCount: {
    title: 'Inventory Count',
    subtitle: 'Inventory count and audit history for stock verification.',
    endpoint: '/v1/inventory/movements',
    defaultType: 'COUNT,INVENTORY_COUNT,STOCK_COUNT',
    showTypeFilter: false,
    refreshLabel: 'Refresh inventory count',
    emptyLabel: 'No inventory count entries found.',
  },
}

export default function InventoryMovementsPage({ mode = 'movements' }) {
  const config = modeConfig[mode] || modeConfig.movements
  const defaults = useMemo(() => ({ branch_id: '', from: monthStart(), to: today(), movement_type: config.defaultType, search: '' }), [config.defaultType])
  const [filters, setFilters] = useState(defaults)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')

  const load = useCallback(async (next = filters) => {
    const response = await api.get(config.endpoint, { params: { ...next, branch_id: next.branch_id || undefined, movement_type: next.movement_type === 'ALL' ? undefined : next.movement_type } })
    setData(response.data)
    if (!next.branch_id && response.data.branches?.[0]?.id) setFilters((current) => ({ ...current, branch_id: String(response.data.branches[0].id) }))
  }, [config.endpoint, filters])

  useEffect(() => {
    setFilters(defaults)
  }, [defaults])

  useEffect(() => { load(defaults).catch((e) => setMessage(getApiErrorMessage(e, `Unable to load ${config.title.toLowerCase()}.`))).finally(() => setLoading(false)) }, [config.title])

  async function apply(e) { e?.preventDefault(); setLoading(true); setMessage(''); try { await load(filters) } catch (err) { setMessage(getApiErrorMessage(err, `Unable to load ${config.title.toLowerCase()}.`)) } finally { setLoading(false) } }
  async function reset() { const next = { ...defaults, branch_id: filters.branch_id }; setFilters(next); setLoading(true); try { await load(next) } finally { setLoading(false) } }

  return <div className="page-stack">
    <div className="page-heading"><div><h1 className="live-page-title">{config.title} <PageRefreshButton label={config.refreshLabel} disabled={loading} onRefresh={() => load(filters)} /></h1><p>{config.subtitle}</p></div></div>
    {message ? <div className="form-alert error">{message}</div> : null}
    <section className="content-card"><form className="inventory-filter-grid inventory-movement-filters" onSubmit={apply}>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}><option value="">Default Branch</option>{(data?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
      {config.showTypeFilter ? <label className="form-field"><span>Type</span><SearchableSelect value={filters.movement_type} onChange={(e) => setFilters((c) => ({ ...c, movement_type: e.target.value }))}><option value="ALL">All Types</option><option value="RETAIL_SALE">Retail Sale</option><option value="ADJUSTMENT">Adjustment</option></SearchableSelect></label> : null}
      <label className="form-field"><span>Search</span><input value={filters.search} onChange={(e) => setFilters((c) => ({ ...c, search: e.target.value }))} placeholder="SKU, product, reference, reason" /></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
    </form></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Date / Time</th><th>Product</th><th>Branch</th><th>Type</th><th>Change</th><th>Before</th><th>After</th><th>Reference</th><th>Reason</th><th>Cost Impact</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.id}><td>{dateTime(row.movement_at)}</td><td><strong>{row.product?.name}</strong><span className="table-subline">{row.product?.sku}</span></td><td>{row.branch?.name || '—'}</td><td>{row.movement_type}</td><td><strong>{row.quantity_change > 0 ? '+' : ''}{row.quantity_change}</strong></td><td>{row.quantity_before}</td><td>{row.quantity_after}</td><td>{row.reference_code || row.sale_code || '—'}</td><td>{row.reason || '—'}</td><td>{formatPeso(Math.abs(Number(row.unit_cost_snapshot || 0) * Number(row.quantity_change || 0)))}</td></tr>)}{!(data?.rows || []).length ? <tr><td colSpan="10"><div className="empty-state">{config.emptyLabel}</div></td></tr> : null}</tbody></table></div></section>
  </div>
}
