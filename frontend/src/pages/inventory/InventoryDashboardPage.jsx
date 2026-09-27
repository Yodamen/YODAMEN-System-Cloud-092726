import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatPeso } from '../../utils/formatters'
import './inventory.css'

function dateTime(value) {
  return value
    ? new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value))
    : '—'
}

function monthStart() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default function InventoryDashboardPage() {
  const [data, setData] = useState(null)
  const [filters, setFilters] = useState({ branch_id: '', product_id: '', category: '', status: 'ALL', sellable: 'ALL' })
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [detail, setDetail] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailMessage, setDetailMessage] = useState('')
  const [movementFilters, setMovementFilters] = useState({ from: monthStart(), to: today(), movement_type: 'ALL' })

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/inventory/dashboard', {
      params: {
        branch_id: next.branch_id || undefined,
        product_id: next.product_id || undefined,
        category: next.category || undefined,
        status: next.status && next.status !== 'ALL' ? next.status : undefined,
        sellable: next.sellable && next.sellable !== 'ALL' ? next.sellable : undefined,
      },
    })
    setData(response.data)
    if (!next.branch_id && response.data.branch_id) setFilters((current) => ({ ...current, branch_id: String(response.data.branch_id) }))
  }, [filters])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Inventory Dashboard.'))).finally(() => setLoading(false))
  }, [])

  async function apply(event) {
    event?.preventDefault()
    setLoading(true)
    setMessage('')
    try {
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Inventory Dashboard.'))
    } finally {
      setLoading(false)
    }
  }

  async function reset() {
    const next = { branch_id: filters.branch_id, product_id: '', category: '', status: 'ALL', sellable: 'ALL' }
    setFilters(next)
    setLoading(true)
    setMessage('')
    try {
      await load(next)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Inventory Dashboard.'))
    } finally {
      setLoading(false)
    }
  }

  async function loadProductDetail(productId, nextMovementFilters = movementFilters) {
    setDetailLoading(true)
    setDetailMessage('')
    try {
      const response = await api.get(`/v1/inventory/dashboard/products/${productId}/movements`, {
        params: {
          branch_id: filters.branch_id || undefined,
          from: nextMovementFilters.from || undefined,
          to: nextMovementFilters.to || undefined,
          movement_type: nextMovementFilters.movement_type !== 'ALL' ? nextMovementFilters.movement_type : undefined,
        },
      })
      setDetail(response.data)
    } catch (error) {
      setDetailMessage(getApiErrorMessage(error, 'Unable to load item movement history.'))
    } finally {
      setDetailLoading(false)
    }
  }

  async function openProduct(productId) {
    const next = { from: monthStart(), to: today(), movement_type: 'ALL' }
    setMovementFilters(next)
    setDetail({ product: { id: productId }, rows: [], movement_types: [] })
    await loadProductDetail(productId, next)
  }

  async function applyMovementFilters(event) {
    event?.preventDefault()
    if (!detail?.product?.id) return
    await loadProductDetail(detail.product.id, movementFilters)
  }

  async function resetMovementFilters() {
    const next = { from: monthStart(), to: today(), movement_type: 'ALL' }
    setMovementFilters(next)
    if (detail?.product?.id) await loadProductDetail(detail.product.id, next)
  }

  const summary = data?.summary || {}
  const options = data?.reference_options || {}
  const productDetail = detail?.product || {}

  return <div className="page-stack">
    <div className="page-heading"><div><h1 className="live-page-title">Inventory Dashboard <PageRefreshButton label="Refresh inventory" disabled={loading} onRefresh={() => load(filters)} /></h1><p>Current inventory position, weighted average cost, sellability, valuation, and item-level movement history.</p></div></div>
    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="metric-grid inventory-summary-grid inventory-summary-grid-v3">
      <article className="metric-card"><span>Active SKUs</span><strong>{summary.active_skus ?? 0}</strong></article>
      <article className="metric-card"><span>Low Stock SKUs</span><strong>{summary.low_stock_skus ?? 0}</strong></article>
      <article className="metric-card"><span>Out of Stock</span><strong>{summary.out_of_stock_skus ?? 0}</strong></article>
      <article className="metric-card"><span>Negative Stock</span><strong>{summary.negative_stock_skus ?? 0}</strong></article>
      <article className="metric-card"><span>Inactive SKUs</span><strong>{summary.inactive_skus ?? 0}</strong></article>
      <article className="metric-card"><span>Inventory Cost Value</span><strong>{formatPeso(summary.cost_value)}</strong></article>
      <article className="metric-card"><span>Potential Retail Value</span><strong>{formatPeso(summary.retail_value)}</strong></article>
    </section>

    <section className="content-card"><form className="inventory-filter-grid inventory-dashboard-filter-grid" onSubmit={apply}>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}>{(data?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>SKU Name</span><SearchableSelect value={filters.product_id} onChange={(e) => setFilters((c) => ({ ...c, product_id: e.target.value }))}><option value="">All SKU Names</option>{(options.products || []).map((row) => <option key={row.id} value={row.id}>{row.name || row.label}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Category</span><SearchableSelect value={filters.category} onChange={(e) => setFilters((c) => ({ ...c, category: e.target.value }))}><option value="">All Categories</option>{(options.categories || []).map((row) => <option key={row} value={row}>{row}</option>)}</SearchableSelect></label>
      <label className="form-field inventory-dashboard-status"><span>Status</span><SearchableSelect value={filters.status} onChange={(e) => setFilters((c) => ({ ...c, status: e.target.value }))}>{(options.statuses || []).map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
      <label className="form-field inventory-dashboard-sellable"><span>Sellable</span><SearchableSelect value={filters.sellable} onChange={(e) => setFilters((c) => ({ ...c, sellable: e.target.value }))}>{(options.sellable || []).map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
    </form></section>

    <section className="content-card">
      {loading ? <div className="empty-state">Loading inventory…</div> : null}
      {!loading ? <div className="table-wrap"><table className="data-table inventory-dashboard-table"><thead><tr><th>Product</th><th>Category</th><th>On Hand</th><th>Reserved</th><th>Available</th><th>Sellable</th><th>Weighted Avg. Cost</th><th>Cost Value</th><th>Retail Value</th><th>Status</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.product_id}>
        <td><button className="inventory-product-link" type="button" onClick={() => openProduct(row.product_id)}><strong>{row.name}</strong><span>{row.sku}</span></button></td>
        <td>{row.category || '—'}</td>
        <td>{row.on_hand} {row.unit}</td>
        <td>{row.reserved}</td>
        <td><strong>{row.available}</strong></td>
        <td><span className={`inventory-sellable-icon ${row.sellable ? 'yes' : 'no'}`} title={row.sellable ? 'Sellable' : 'Not sellable'}>{row.sellable ? 'Yes' : 'No'}</span></td>
        <td><strong>{formatPeso(row.weighted_average_cost)}</strong><span className="table-subline">per {row.unit}</span></td>
        <td>{formatPeso(row.cost_value)}</td>
        <td>{row.sellable ? formatPeso(row.retail_value) : '—'}</td>
        <td><span className={`status-pill ${row.status === 'NEGATIVE STOCK' ? 'inventory-status-negative' : row.status === 'OUT OF STOCK' ? 'inventory-status-out' : row.status === 'LOW STOCK' ? 'inventory-status-low' : row.status === 'INACTIVE' ? 'neutral' : 'success'}`}>{row.status}</span></td>
      </tr>)}{!(data?.rows || []).length ? <tr><td colSpan="10"><div className="empty-state">No inventory items found.</div></td></tr> : null}</tbody></table></div> : null}
    </section>

    {detail ? <Modal title={productDetail.name || 'Inventory Item'} subtitle={productDetail.sku ? `${productDetail.sku} · Movement history` : 'Movement history'} onClose={() => { setDetail(null); setDetailMessage('') }} wide className="inventory-detail-modal">
      {detailMessage ? <div className="form-alert error">{detailMessage}</div> : null}
      <section className="inventory-detail-summary">
        <article><span>On Hand</span><strong>{productDetail.on_hand ?? 0} {productDetail.unit || ''}</strong></article>
        <article><span>Available</span><strong>{productDetail.available ?? 0} {productDetail.unit || ''}</strong></article>
        <article><span>Weighted Avg. Cost</span><strong>{formatPeso(productDetail.weighted_average_cost || 0)}</strong></article>
        <article><span>Inventory Cost</span><strong>{formatPeso(productDetail.cost_value || 0)}</strong></article>
        <article><span>Sellable</span><strong><span className={`inventory-sellable-icon ${productDetail.sellable ? 'yes' : 'no'}`}>{productDetail.sellable ? 'Yes' : 'No'}</span></strong></article>
        <article><span>Retail Value</span><strong>{productDetail.sellable ? formatPeso(productDetail.retail_value || 0) : '—'}</strong></article>
      </section>

      <form className="inventory-detail-filters" onSubmit={applyMovementFilters}>
        <label className="form-field"><span>From</span><input type="date" value={movementFilters.from} onChange={(e) => setMovementFilters((c) => ({ ...c, from: e.target.value }))} /></label>
        <label className="form-field"><span>To</span><input type="date" value={movementFilters.to} onChange={(e) => setMovementFilters((c) => ({ ...c, to: e.target.value }))} /></label>
        <label className="form-field"><span>Movement Type</span><SearchableSelect value={movementFilters.movement_type} onChange={(e) => setMovementFilters((c) => ({ ...c, movement_type: e.target.value }))}><option value="ALL">All Types</option>{(detail.movement_types || []).map((type) => <option key={type} value={type}>{String(type).replaceAll('_', ' ')}</option>)}</SearchableSelect></label>
        <div className="filter-actions-inline"><button className="primary-button" type="submit" disabled={detailLoading}>Apply</button><button className="secondary-button" type="button" onClick={resetMovementFilters} disabled={detailLoading}>Reset</button></div>
      </form>

      <div className="table-wrap inventory-detail-movements"><table className="data-table inventory-detail-movement-table"><thead><tr><th>Date / Time</th><th>Type</th><th>Change</th><th>Before</th><th>After</th><th>Cost Snapshot</th><th>Cost Impact</th><th>Reference</th><th>Reason</th></tr></thead><tbody>{detailLoading ? <tr><td colSpan="9"><div className="empty-state">Loading movement history…</div></td></tr> : (detail.rows || []).map((row) => <tr key={row.id}><td>{dateTime(row.movement_at)}</td><td>{String(row.movement_type || '').replaceAll('_', ' ')}</td><td><strong>{row.quantity_change > 0 ? '+' : ''}{row.quantity_change}</strong></td><td>{row.quantity_before}</td><td>{row.quantity_after}</td><td>{formatPeso(row.unit_cost_snapshot)}</td><td>{formatPeso(Math.abs(Number(row.quantity_change || 0) * Number(row.unit_cost_snapshot || 0)))}</td><td>{row.reference_code || row.sale_code || '—'}</td><td>{row.reason || '—'}</td></tr>)}{!detailLoading && !(detail.rows || []).length ? <tr><td colSpan="9"><div className="empty-state">No movements found for this item in the selected period.</div></td></tr> : null}</tbody></table></div>
      <p className="inventory-cost-note">Current weighted average cost updates when stock is received. Outbound transactions preserve the weighted cost snapshot used when the stock movement is posted.</p>
    </Modal> : null}
  </div>
}
