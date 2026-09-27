import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'
import './inventory-control.css'

const stockTypeLabels = {
  INVENTORY: 'Inventory',
  NON_INVENTORY: 'Non-inventory',
  ASSET: 'Asset',
}

function dateValue(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
function monthStart() { const d = new Date(); return dateValue(new Date(d.getFullYear(), d.getMonth(), 1)) }
function today() { return dateValue(new Date()) }
function formatDate(value) {
  if (!value) return '—'
  const [year, month, day] = String(value).slice(0, 10).split('-').map(Number)
  const names = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.']
  return `${names[(month || 1) - 1]} ${day}, ${year}`
}
function formatTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(date)
}
function countTimeLine(row) {
  const finishedAt = row.approved_at || row.cancelled_at
  if (finishedAt) return `Finished ${formatTime(finishedAt)}`
  return `Posted ${formatTime(row.created_at)}`
}

function AuditCategoryPicker({ categories, selected, fullCount, onChange }) {
  const [open, setOpen] = useState(false)
  const label = fullCount
    ? 'Full Count'
    : selected.length
      ? `${selected.length} categor${selected.length === 1 ? 'y' : 'ies'} selected`
      : 'Select categories'

  function toggleCategory(category) {
    const exists = selected.includes(category)
    onChange({ fullCount: false, categories: exists ? selected.filter((row) => row !== category) : [...selected, category] })
  }

  return (
    <div className={`audit-category-multiselect ${open ? 'open' : ''}`}>
      <span className="audit-category-label">Categories</span>
      <button className="audit-category-trigger" type="button" onClick={() => setOpen((current) => !current)}>
        <span>{label}</span><span aria-hidden="true">⌄</span>
      </button>
      {open ? (
        <div className="audit-category-menu">
          <label className="audit-category-option audit-category-full-option">
            <input type="checkbox" checked={fullCount} onChange={() => onChange({ fullCount: true, categories: [] })} />
            <span><strong>Full Count</strong><small>All categories under this stock type</small></span>
          </label>
          {categories.map((category) => (
            <label className="audit-category-option" key={category}>
              <input type="checkbox" checked={!fullCount && selected.includes(category)} onChange={() => toggleCategory(category)} />
              <span>{category}</span>
            </label>
          ))}
        </div>
      ) : null}
    </div>
  )
}

export default function InventoryCountPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ options: { branches: [], products: [], categories_by_stock_type: {} }, counts: [] })
  const [filters, setFilters] = useState({ branch_id: '', from: monthStart(), to: today(), status: 'ALL', count_scope: 'ALL', category: '' })
  const [startForm, setStartForm] = useState(null)
  const [activeCount, setActiveCount] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [countConfirmation, setCountConfirmation] = useState(null)

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/inventory/counts', {
      params: {
        ...next,
        branch_id: next.branch_id || undefined,
        status: next.status === 'ALL' ? undefined : next.status,
        count_scope: next.count_scope === 'ALL' ? undefined : next.count_scope,
        category: next.category || undefined,
      },
    })
    setData(response.data)
    if (!next.branch_id && response.data.options?.branches?.[0]?.id) setFilters((current) => ({ ...current, branch_id: String(response.data.options.branches[0].id) }))
  }, [filters])

  useEffect(() => {
    const timer = window.setTimeout(() => load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load audit counts.'))).finally(() => setLoading(false)), 0)
    return () => window.clearTimeout(timer)
  }, [])

  const homeFilterStockType = useMemo(() => {
    if (['INVENTORY', 'NON_INVENTORY', 'ASSET'].includes(filters.count_scope)) return filters.count_scope
    if (filters.count_scope === 'FULL_INVENTORY') return 'INVENTORY'
    if (filters.count_scope === 'FULL_NON_INVENTORY') return 'NON_INVENTORY'
    if (filters.count_scope === 'FULL_ASSET') return 'ASSET'
    return ''
  }, [filters.count_scope])

  const homeCategories = useMemo(() => homeFilterStockType ? (data.options?.categories_by_stock_type?.[homeFilterStockType] || []) : [], [data.options, homeFilterStockType])

  async function apply(event) {
    event?.preventDefault(); setLoading(true); setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load audit counts.')) } finally { setLoading(false) }
  }

  async function reset() {
    const next = { branch_id: filters.branch_id, from: monthStart(), to: today(), status: 'ALL', count_scope: 'ALL', category: '' }
    setFilters(next); setLoading(true); setMessage('')
    try { await load(next) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load audit counts.')) } finally { setLoading(false) }
  }

  function openStart() {
    const allIds = (data.options?.products || []).map((product) => Number(product.id))
    setStartForm({
      branch_id: filters.branch_id || String(data.options?.branches?.[0]?.id || ''),
      count_date: today(),
      stock_scope: 'FULL',
      category_full_count: true,
      categories: [],
      product_ids: allIds,
      notes: '',
      supporting_files: [],
    })
  }

  const startCategories = useMemo(() => {
    if (!startForm || startForm.stock_scope === 'FULL') return []
    return data.options?.categories_by_stock_type?.[startForm.stock_scope] || []
  }, [data.options, startForm])

  const startCandidateProducts = useMemo(() => {
    if (!startForm) return []
    let rows = data.options?.products || []
    if (startForm.stock_scope !== 'FULL') rows = rows.filter((product) => product.stock_type === startForm.stock_scope)
    if (startForm.stock_scope !== 'FULL' && !startForm.category_full_count) {
      if (!startForm.categories.length) return []
      rows = rows.filter((product) => startForm.categories.includes(product.category))
    }
    return rows
  }, [data.options, startForm])

  function resetSelectedProducts(nextForm) {
    let rows = data.options?.products || []
    if (nextForm.stock_scope !== 'FULL') rows = rows.filter((product) => product.stock_type === nextForm.stock_scope)
    if (nextForm.stock_scope !== 'FULL' && !nextForm.category_full_count) {
      rows = nextForm.categories.length ? rows.filter((product) => nextForm.categories.includes(product.category)) : []
    }
    return { ...nextForm, product_ids: rows.map((product) => Number(product.id)) }
  }

  function changeStartScope(scope) {
    setStartForm((current) => resetSelectedProducts({ ...current, stock_scope: scope, category_full_count: true, categories: [] }))
  }

  function changeStartCategories({ fullCount, categories }) {
    setStartForm((current) => resetSelectedProducts({ ...current, category_full_count: fullCount, categories }))
  }

  function toggleSelectedProduct(productId) {
    setStartForm((current) => {
      const id = Number(productId)
      const selected = current.product_ids.includes(id)
      return { ...current, product_ids: selected ? current.product_ids.filter((row) => row !== id) : [...current.product_ids, id] }
    })
  }

  function toggleAllCandidateProducts(selectAll) {
    setStartForm((current) => ({ ...current, product_ids: selectAll ? startCandidateProducts.map((product) => Number(product.id)) : [] }))
  }

  async function startCount(event) {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const isFull = startForm.stock_scope === 'FULL'
      const attachmentTokens = await uploadSupportingDocuments(startForm.supporting_files || [], 'inventory-count', 'AUDIT_COUNT_SUPPORT')
      const payload = {
        branch_id: Number(startForm.branch_id),
        count_date: startForm.count_date,
        scope_type: isFull ? 'FULL' : (startForm.category_full_count ? 'STOCK_TYPE' : 'CATEGORIES'),
        stock_type: isFull ? null : startForm.stock_scope,
        categories: isFull || startForm.category_full_count ? [] : startForm.categories,
        product_ids: startForm.product_ids,
        notes: startForm.notes,
        attachment_tokens: attachmentTokens,
      }
      const response = await api.post('/v1/inventory/counts', payload)
      setStartForm(null)
      setActiveCount(response.data.count)
      await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to start audit count.')) } finally { setSaving(false) }
  }

  async function openCount(id) {
    setSaving(true); setMessage('')
    try {
      const response = await api.get(`/v1/inventory/counts/${id}`)
      setActiveCount(response.data.count)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to open audit count.')) } finally { setSaving(false) }
  }

  function patchCountLine(index, patch) {
    setActiveCount((current) => ({ ...current, lines: current.lines.map((line, i) => i === index ? { ...line, ...patch } : line) }))
  }

  async function saveDraft() {
    setSaving(true); setMessage('')
    try {
      const response = await api.put(`/v1/inventory/counts/${activeCount.id}`, {
        notes: activeCount.notes || '',
        lines: activeCount.lines.map((line) => ({ id: line.id, actual_quantity: line.actual_quantity === '' ? null : line.actual_quantity, line_notes: line.line_notes || '' })),
      })
      setActiveCount(response.data.count)
      await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to save audit count.')) } finally { setSaving(false) }
  }

  async function submitCount() {
    setSaving(true); setMessage('')
    try {
      await api.put(`/v1/inventory/counts/${activeCount.id}`, {
        notes: activeCount.notes || '',
        lines: activeCount.lines.map((line) => ({ id: line.id, actual_quantity: line.actual_quantity === '' ? null : line.actual_quantity, line_notes: line.line_notes || '' })),
      })
      const response = await api.post(`/v1/inventory/counts/${activeCount.id}/submit`)
      setActiveCount(response.data.count)
      await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to submit audit count.')) } finally { setSaving(false) }
  }

  function approveCount() {
    setCountConfirmation('APPROVE')
  }

  function cancelCount() {
    setCountConfirmation('CANCEL')
  }

  async function confirmCountAction() {
    if (!activeCount || !countConfirmation) return
    const action = countConfirmation === 'APPROVE' ? 'approve' : 'cancel'
    setSaving(true); setMessage('')
    try {
      const response = await api.post(`/v1/inventory/counts/${activeCount.id}/${action}`)
      setActiveCount(response.data.count)
      setCountConfirmation(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, countConfirmation === 'APPROVE' ? 'Unable to approve audit count.' : 'Unable to cancel audit count.'))
    } finally { setSaving(false) }
  }

  const totalVarianceCost = useMemo(() => (activeCount?.lines || []).reduce((sum, line) => sum + (Number(line.variance_quantity || 0) * Number(line.unit_cost_snapshot || 0)), 0), [activeCount])
  const draft = activeCount?.status === 'DRAFT'
  const submitted = activeCount?.status === 'SUBMITTED'

  return <div className="page-stack">
    <section className="page-heading live-page-heading"><div><h1 className="live-page-title">Audit Count <PageRefreshButton label="Refresh audit counts" disabled={loading || saving} onRefresh={() => load(filters)} /></h1><p>Perform blind physical counts, review variances, and post approved corrections to inventory.</p></div>{hasPermission('inventory-count', 'CREATE') ? <button className="primary-button" type="button" onClick={openStart}>Start Audit Count</button> : null}</section>
    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="content-card"><form className="inventory-control-filters inventory-count-filters inventory-count-filters-v91" onSubmit={apply}>
      <label className="form-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(e) => setFilters((c) => ({ ...c, branch_id: e.target.value }))}>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((c) => ({ ...c, from: e.target.value }))} /></label>
      <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((c) => ({ ...c, to: e.target.value }))} /></label>
      <label className="form-field"><span>Stock Type</span><SearchableSelect value={filters.count_scope} onChange={(e) => setFilters((c) => ({ ...c, count_scope: e.target.value, category: '' }))}>
        <option value="ALL">All Counts</option>
        <option value="FULL">Full Count</option>
        <option value="FULL_INVENTORY">Full Count - Inventory</option>
        <option value="FULL_NON_INVENTORY">Full Count - Non-inventory</option>
        <option value="FULL_ASSET">Full Count - Asset</option>
        <option value="INVENTORY">Inventory</option>
        <option value="NON_INVENTORY">Non-inventory</option>
        <option value="ASSET">Asset</option>
      </SearchableSelect></label>
      <label className="form-field"><span>Category</span><SearchableSelect value={filters.category} disabled={!['INVENTORY', 'NON_INVENTORY', 'ASSET'].includes(filters.count_scope)} onChange={(e) => setFilters((c) => ({ ...c, category: e.target.value }))}><option value="">All Categories</option>{homeCategories.map((category) => <option key={category} value={category}>{category}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(e) => setFilters((c) => ({ ...c, status: e.target.value }))}><option value="ALL">All Statuses</option><option value="DRAFT">Draft</option><option value="SUBMITTED">Submitted</option><option value="APPROVED">Approved</option><option value="CANCELLED">Cancelled</option></SearchableSelect></label>
      <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
    </form></section>

    <section className="content-card"><div className="table-wrap"><table className="data-table audit-count-home-table"><thead><tr><th>Count ID</th><th>Date</th><th>Branch</th><th>Scope</th><th>Items</th><th>Variance Lines</th><th>Counted By</th><th>Status</th><th>Action</th></tr></thead><tbody>{(data.counts || []).map((row) => <tr key={row.id}><td><strong>{row.code}</strong></td><td><strong>{formatDate(row.count_date)}</strong><span className="table-subline">{countTimeLine(row)}</span></td><td>{row.branch?.name || '—'}</td><td>{row.scope_label || '—'}</td><td>{row.line_count}</td><td>{row.variance_lines}</td><td>{row.created_by || '—'}</td><td><span className={`status-pill ${row.status === 'APPROVED' ? 'success' : row.status === 'SUBMITTED' ? 'warning' : row.status === 'CANCELLED' ? 'danger' : 'neutral'}`}>{row.status}</span></td><td><button className="secondary-button compact-button" type="button" onClick={() => openCount(row.id)}>View</button></td></tr>)}{!loading && !(data.counts || []).length ? <tr><td colSpan="9"><div className="empty-state">No audit count sessions found.</div></td></tr> : null}</tbody></table></div></section>

    {startForm ? <Modal title="Start Audit Count" subtitle="Choose the stock type, categories, and exact items to be physically counted. System quantities remain hidden while the count is Draft." onClose={() => setStartForm(null)} wide>
      <form className="inventory-audit-start-form inventory-audit-start-form-v91" onSubmit={startCount}>
        <div className="audit-start-top-grid">
          <label className="form-field"><span>Branch</span><SearchableSelect required value={startForm.branch_id} onChange={(e) => setStartForm({ ...startForm, branch_id: e.target.value })}>{(data.options?.branches || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Count Date</span><input required type="date" max={today()} value={startForm.count_date} onChange={(e) => setStartForm({ ...startForm, count_date: e.target.value })} /></label>
          <label className="form-field"><span>Scope</span><SearchableSelect value={startForm.stock_scope} onChange={(e) => changeStartScope(e.target.value)}><option value="FULL">Full Count</option><option value="INVENTORY">Inventory</option><option value="NON_INVENTORY">Non-inventory</option><option value="ASSET">Asset</option></SearchableSelect></label>
          {startForm.stock_scope !== 'FULL' ? <AuditCategoryPicker categories={startCategories} selected={startForm.categories} fullCount={startForm.category_full_count} onChange={changeStartCategories} /> : <div className="audit-full-count-note"><span>Categories</span><strong>All Categories</strong></div>}
        </div>

        <div className="audit-item-selection-card">
          <div className="audit-item-selection-heading">
            <div><strong>Items for Counting</strong><span>{startCandidateProducts.length} matching item(s), {startForm.product_ids.length} selected</span></div>
            <div className="audit-item-selection-actions"><button type="button" className="secondary-button compact-button" onClick={() => toggleAllCandidateProducts(true)}>Select All</button><button type="button" className="secondary-button compact-button" onClick={() => toggleAllCandidateProducts(false)}>Clear</button></div>
          </div>
          <div className="table-wrap audit-scope-table-wrap"><table className="data-table audit-scope-item-table"><thead><tr><th className="audit-select-column">Count</th><th>Item</th><th>Stock Type</th><th>Category</th><th>Basic UOM</th></tr></thead><tbody>{startCandidateProducts.map((product) => <tr key={product.id}><td><input className="audit-item-checkbox" type="checkbox" checked={startForm.product_ids.includes(Number(product.id))} onChange={() => toggleSelectedProduct(product.id)} /></td><td><strong>{product.name}</strong><span className="table-subline">{product.sku}</span></td><td>{stockTypeLabels[product.stock_type] || product.stock_type}</td><td>{product.category || '—'}</td><td>{product.unit || '—'}</td></tr>)}{!startCandidateProducts.length ? <tr><td colSpan="5"><div className="empty-state compact-empty">No active items match the selected scope.</div></td></tr> : null}</tbody></table></div>
        </div>

        <label className="form-field"><span>Notes</span><textarea rows="3" value={startForm.notes} onChange={(e) => setStartForm({ ...startForm, notes: e.target.value })} /></label>
        <SupportingDocumentField files={startForm.supporting_files || []} onChange={(files) => setStartForm({ ...startForm, supporting_files: files })} label="Supporting Document" hint="Optional. Attach physical count sheets, signed audit forms or supporting photos." />
        <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setStartForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving || !startForm.product_ids.length}>{saving ? 'Starting...' : 'Start Blind Count'}</button></div>
      </form>
    </Modal> : null}

    {activeCount ? <Modal title={`Audit Count · ${activeCount.code}`} subtitle={`${formatDate(activeCount.count_date)} · ${activeCount.branch?.name || ''} · ${activeCount.scope_label || activeCount.status}`} onClose={() => setActiveCount(null)} wide>
      <div className="inventory-audit-banner"><div><strong>{activeCount.blind_count ? 'Blind Count Active' : 'Count Review'}</strong><span>{activeCount.blind_count ? 'System quantities and variances stay hidden until you submit the physical count.' : 'System quantity, actual count, and variance are now visible for review.'}</span></div><span className={`status-pill ${activeCount.status === 'APPROVED' ? 'success' : activeCount.status === 'SUBMITTED' ? 'warning' : activeCount.status === 'CANCELLED' ? 'danger' : 'neutral'}`}>{activeCount.status}</span></div>
      <div className="table-wrap inventory-count-table-wrap"><table className="data-table"><thead><tr><th>SKU / Item</th><th>Stock Type</th><th>Category</th>{!activeCount.blind_count ? <th>System Qty</th> : null}<th>Actual Count</th>{!activeCount.blind_count ? <th>Variance</th> : null}<th>Notes</th></tr></thead><tbody>{(activeCount.lines || []).map((line, index) => <tr key={line.id}><td><strong>{line.name}</strong><span className="table-subline">{line.sku} · {line.unit}</span></td><td>{stockTypeLabels[line.stock_type] || line.stock_type}</td><td>{line.category || '—'}</td>{!activeCount.blind_count ? <td>{line.system_quantity}</td> : null}<td>{draft ? <input className="inventory-count-input" type="number" min="0" step="0.0001" value={line.actual_quantity ?? ''} onChange={(e) => patchCountLine(index, { actual_quantity: e.target.value })} placeholder="Enter count" /> : <strong>{line.actual_quantity}</strong>}</td>{!activeCount.blind_count ? <td><span className={`status-pill ${Number(line.variance_quantity || 0) === 0 ? 'success' : 'warning'}`}>{Number(line.variance_quantity || 0) > 0 ? '+' : ''}{line.variance_quantity}</span></td> : null}<td>{draft ? <input className="inventory-count-note-input" value={line.line_notes || ''} onChange={(e) => patchCountLine(index, { line_notes: e.target.value })} placeholder="Optional" /> : (line.line_notes || '—')}</td></tr>)}</tbody></table></div>
      {!activeCount.blind_count ? <div className="inventory-audit-summary"><div><span>Variance Lines</span><strong>{(activeCount.lines || []).filter((line) => Number(line.variance_quantity || 0) !== 0).length}</strong></div><div><span>Estimated Variance Cost</span><strong>{formatPeso(totalVarianceCost)}</strong></div></div> : null}
      <label className="form-field"><span>Count Notes</span><textarea rows="3" disabled={!draft} value={activeCount.notes || ''} onChange={(e) => setActiveCount({ ...activeCount, notes: e.target.value })} /></label>
      <div className="modal-actions audit-count-actions">
        {(draft || submitted) && hasPermission('inventory-count', 'EDIT') ? <button className="secondary-button" type="button" onClick={cancelCount} disabled={saving}>Cancel Count</button> : null}
        {draft && hasPermission('inventory-count', 'EDIT') ? <button className="secondary-button" type="button" onClick={saveDraft} disabled={saving}>Save Draft</button> : null}
        {draft && hasPermission('inventory-count', 'EDIT') ? <button className="primary-button" type="button" onClick={submitCount} disabled={saving}>Submit Count</button> : null}
        {submitted && hasPermission('inventory-count', 'APPROVE') ? <button className="primary-button" type="button" onClick={approveCount} disabled={saving}>Approve & Post Variance</button> : null}
        {!draft && !submitted ? <button className="secondary-button" type="button" onClick={() => setActiveCount(null)}>Close</button> : null}
      </div>
    </Modal> : null}

    {countConfirmation && activeCount ? <ActionConfirmationModal
      title={countConfirmation === 'APPROVE' ? 'Approve Audit Count' : 'Cancel Audit Count'}
      subtitle={activeCount.code}
      message={countConfirmation === 'APPROVE'
        ? 'This will post every approved quantity variance to inventory. The movement becomes part of the inventory audit trail.'
        : 'This will cancel the audit count. No inventory movement will be posted.'}
      details={[
        { label: 'Branch', value: activeCount.branch?.name || '—' },
        { label: 'Count Date', value: formatDate(activeCount.count_date) },
        { label: 'Variance Lines', value: String((activeCount.lines || []).filter((line) => Number(line.variance_quantity || 0) !== 0).length) },
      ]}
      confirmLabel={countConfirmation === 'APPROVE' ? 'Approve & Post' : 'Cancel Count'}
      danger={countConfirmation === 'CANCEL'}
      busy={saving}
      onCancel={() => setCountConfirmation(null)}
      onConfirm={confirmCountAction}
    /> : null}
  </div>
}
