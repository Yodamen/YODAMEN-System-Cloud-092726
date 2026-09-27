import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import './inventory-control.css'
import './inventory-update79.css'

const emptySupplier = {
  id: null,
  company_name: '',
  code_name: '',
  contact_person: '',
  contact_number: '',
  channel: '',
  address: '',
  item_ids: [],
  store_link: '',
  status: 'ACTIVE',
}

const emptyItem = {
  sku: '',
  barcode: '',
  name: '',
  stock_type: 'INVENTORY',
  category: '',
  brand: '',
  unit: 'pc',
  purchase_uom: 'pc',
  purchase_to_basic_factor: '1',
  purchase_uom_cost: '0',
  selling_uom: 'pc',
  selling_to_basic_factor: '1',
  consumption_uom: 'pc',
  consumption_to_basic_factor: '1',
  retail_available: true,
  unit_cost: '0',
  selling_price: '0',
  selling_price_effective_from: '',
  reorder_point: '0',
  commission_eligible: false,
  status: 'ACTIVE',
  notes: '',
}

function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function derivedBasicCost(form) {
  const purchaseCost = Number(form?.purchase_uom_cost || 0)
  const factor = Number(form?.purchase_to_basic_factor || 0)
  if (!Number.isFinite(purchaseCost) || !Number.isFinite(factor) || factor <= 0) return 0
  return purchaseCost / factor
}

function derivedConsumptionCost(form) {
  const basicCost = derivedBasicCost(form)
  const factor = Number(form?.consumption_to_basic_factor || 0)
  if (!Number.isFinite(basicCost) || !Number.isFinite(factor) || factor <= 0) return 0
  return basicCost / factor
}

function weightedConsumptionCost(form) {
  const weightedBasicCost = Number(form?.unit_cost || 0)
  const factor = Number(form?.consumption_to_basic_factor || 0)
  if (!Number.isFinite(weightedBasicCost) || !Number.isFinite(factor) || factor <= 0) return 0
  return weightedBasicCost / factor
}

function normalize(options = []) {
  return options.map((row) => typeof row === 'string'
    ? { code: row, value: row }
    : { code: row.code || row.value, value: row.value || row.display_value || row.name || row.code })
}

function externalUrl(value) {
  const raw = String(value || '').trim()
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw)) return raw
  return `https://${raw}`
}

export default function SupplierMasterlistPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ suppliers: [], options: { channels: [], items: [], suppliers: [] } })
  const [productData, setProductData] = useState({ products: [], reference_options: {} })
  const [filters, setFilters] = useState({ supplier_id: '', channel: '', status: 'ALL' })
  const [form, setForm] = useState(null)
  const [itemForm, setItemForm] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [itemsOpen, setItemsOpen] = useState(false)

  const load = useCallback(async (next = filters) => {
    const [suppliers, products] = await Promise.all([
      api.get('/v1/inventory/suppliers', {
        params: {
          supplier_id: next.supplier_id || undefined,
          channel: next.channel || undefined,
          status: next.status !== 'ALL' ? next.status : undefined,
        },
      }),
      api.get('/v1/inventory/products'),
    ])
    setData(suppliers.data)
    setProductData(products.data)
  }, [filters])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load supplier masterlist.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [])

  const refs = productData.reference_options || {}
  const categoriesByType = refs.categories_by_stock_type || {}
  const uoms = normalize(refs.product_uoms || [])
  const itemCategories = itemForm ? normalize(categoriesByType[itemForm.stock_type] || []) : []

  async function apply(event) {
    event.preventDefault()
    setLoading(true)
    setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load supplier masterlist.')) } finally { setLoading(false) }
  }

  async function reset() {
    const next = { supplier_id: '', channel: '', status: 'ALL' }
    setFilters(next)
    setLoading(true)
    try { await load(next) } finally { setLoading(false) }
  }

  function openCreate() {
    setItemsOpen(false)
    setForm({ ...emptySupplier, item_ids: [] })
  }

  function openEdit(row) {
    setItemsOpen(false)
    setForm({ ...row, item_ids: [...(row.item_ids || [])] })
  }

  function toggleItem(id) {
    setForm((current) => ({
      ...current,
      item_ids: current.item_ids.includes(id)
        ? current.item_ids.filter((value) => value !== id)
        : [...current.item_ids, id],
    }))
  }

  async function saveSupplier(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      if (form.id) await api.put(`/v1/inventory/suppliers/${form.id}`, form)
      else await api.post('/v1/inventory/suppliers', form)
      setForm(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save supplier.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggleSupplier(row) {
    setMessage('')
    try {
      await api.patch(`/v1/inventory/suppliers/${row.id}/toggle`)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update supplier status.'))
    }
  }

  function openNewItem() {
    const firstUom = uoms[0]?.value || 'pc'
    setItemForm({ ...emptyItem, unit: firstUom, purchase_uom: firstUom, selling_uom: firstUom, consumption_uom: firstUom, selling_price_effective_from: today() })
  }

  function changeItemStockType(nextStockType) {
    setItemForm((current) => ({
      ...current,
      stock_type: nextStockType,
      category: '',
      retail_available: nextStockType === 'INVENTORY' ? current.retail_available : false,
      selling_price: nextStockType === 'INVENTORY' ? current.selling_price : '0',
      reorder_point: nextStockType === 'INVENTORY' ? current.reorder_point : '0',
      commission_eligible: nextStockType === 'INVENTORY' ? current.commission_eligible : false,
    }))
  }

  async function saveNewItem(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const tracked = itemForm.stock_type === 'INVENTORY'
      const retailAvailable = tracked && Boolean(itemForm.retail_available)
      const response = await api.post('/v1/inventory/products', {
        ...itemForm,
        unit_cost: Number(derivedBasicCost(itemForm).toFixed(4)),
        purchase_uom_cost: Number(itemForm.purchase_uom_cost || 0),
        basic_uom_cost: Number(derivedBasicCost(itemForm).toFixed(4)),
        purchase_to_basic_factor: Number(itemForm.purchase_to_basic_factor || 1),
        selling_to_basic_factor: Number(itemForm.selling_to_basic_factor || 1),
        consumption_to_basic_factor: Number(itemForm.consumption_to_basic_factor || 1),
        retail_available: retailAvailable,
        selling_price: retailAvailable ? Number(itemForm.selling_price || 0) : 0,
        reorder_point: tracked ? Number(itemForm.reorder_point || 0) : 0,
        commission_eligible: retailAvailable ? Boolean(itemForm.commission_eligible) : false,
      })

      const createdId = Number(response.data?.product?.id)
      const [suppliers, products] = await Promise.all([
        api.get('/v1/inventory/suppliers'),
        api.get('/v1/inventory/products'),
      ])
      setData(suppliers.data)
      setProductData(products.data)
      if (createdId) {
        setForm((current) => ({ ...current, item_ids: [...new Set([...(current.item_ids || []), createdId])] }))
      }
      setItemForm(null)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to create inventory item.'))
    } finally {
      setSaving(false)
    }
  }

  const visibleItems = useMemo(
    () => (data.options?.items || []).filter((item) => ['ACTIVE', 'PENDING_APPROVAL'].includes(item.status)),
    [data.options?.items],
  )

  return <div className="page-stack">
    <section className="page-heading live-page-heading">
      <div>
        <h1 className="live-page-title">Supplier Masterlist <PageRefreshButton label="Refresh suppliers" disabled={loading || saving} onRefresh={() => load(filters)} /></h1>
        <p>Maintain supplier contacts, ordering channels, store links, and the items each supplier can provide.</p>
      </div>
      {hasPermission('supplier-master', 'CREATE') ? <button className="primary-button" type="button" onClick={openCreate}>Add Supplier</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="content-card">
      <form className="supplier-filter-row supplier-filter-row-v2" onSubmit={apply}>
        <label className="form-field"><span>Supplier Name</span><SearchableSelect value={filters.supplier_id} onChange={(e) => setFilters((current) => ({ ...current, supplier_id: e.target.value }))}><option value="">All Suppliers</option>{(data.options?.suppliers || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Channel</span><SearchableSelect value={filters.channel} onChange={(e) => setFilters((current) => ({ ...current, channel: e.target.value }))}><option value="">All Channels</option>{(data.options?.channels || []).map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(e) => setFilters((current) => ({ ...current, status: e.target.value }))}><option value="ALL">All</option><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
        <div className="filter-actions-inline"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={reset}>Reset</button></div>
      </form>
    </section>

    <section className="content-card">
      <div className="table-wrap">
        <table className="data-table supplier-master-table">
          <thead><tr><th>Supplier</th><th>Contact Person</th><th>Contact Number</th><th>Channel</th><th>Items</th><th>Store Link</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
          <tbody>
            {(data.suppliers || []).map((row) => <tr key={row.id}>
              <td><strong>{row.company_name}</strong><span className="table-subline">{row.code_name || row.code}</span></td>
              <td>{row.contact_person || '—'}</td>
              <td>{row.contact_number || '—'}</td>
              <td>{row.channel || '—'}</td>
              <td>{row.items?.length || 0}</td>
              <td>{row.store_link ? <a href={externalUrl(row.store_link)} target="_blank" rel="noreferrer">Visit</a> : '-'}</td>
              <td><span className={`status-pill ${row.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{row.status}</span></td>
              <td className="action-cell"><ActionMenu items={[
                hasPermission('supplier-master', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(row) } : null,
                hasPermission('supplier-master', 'EDIT') ? { label: row.status === 'ACTIVE' ? 'Deactivate' : 'Activate', onClick: () => toggleSupplier(row) } : null,
              ]} /></td>
            </tr>)}
            {!loading && !(data.suppliers || []).length ? <tr><td colSpan="8"><div className="empty-state">No suppliers found.</div></td></tr> : null}
          </tbody>
        </table>
      </div>
    </section>

    {form ? <Modal title={form.id ? 'Edit Supplier' : 'Add Supplier'} onClose={() => setForm(null)} wide className="supplier-master-modal">
      <form className="form-grid supplier-form" onSubmit={saveSupplier}>
        <label className="form-field"><span>Supplier Name*</span><input required value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} /></label>
        <label className="form-field"><span>Code Name*</span><input required value={form.code_name || ''} onChange={(e) => setForm({ ...form, code_name: e.target.value })} placeholder="Short supplier name" /></label>
        <label className="form-field"><span>Contact Person</span><input value={form.contact_person || ''} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} /></label>
        <label className="form-field"><span>Contact Number</span><input value={form.contact_number || ''} onChange={(e) => setForm({ ...form, contact_number: e.target.value })} /></label>
        <label className="form-field"><span>Channel*</span><SearchableSelect required value={form.channel || ''} onChange={(e) => setForm({ ...form, channel: e.target.value })}><option value="">Select channel</option>{(data.options?.channels || []).map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field form-span-2"><span>Address</span><textarea rows="2" value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} /></label>
        <label className="form-field"><span>Store Link</span><input value={form.store_link || ''} onChange={(e) => setForm({ ...form, store_link: e.target.value })} placeholder="https://..." /></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>

        <div className="supplier-items-section form-span-2">
          <div className="supplier-item-picker-row">
            <div className={`supplier-multiselect ${itemsOpen ? 'open' : ''}`}>
              <span className="supplier-picker-label">Items</span>
              <button className="supplier-multiselect-trigger" type="button" onClick={() => setItemsOpen((current) => !current)}>
                <span>{form.item_ids.length ? `${form.item_ids.length} item${form.item_ids.length === 1 ? '' : 's'} selected` : 'Select items supplied'}</span>
                <span aria-hidden="true">⌄</span>
              </button>
              {itemsOpen ? <div className="supplier-multiselect-menu">{visibleItems.map((item) => <label key={item.id} className="supplier-multiselect-option"><input type="checkbox" checked={form.item_ids.includes(item.id)} onChange={() => toggleItem(item.id)} /><span className="supplier-item-option-copy"><strong>{item.name}</strong><small>{String(item.stock_type || '').replaceAll('_', ' ')}</small></span></label>)}{!visibleItems.length ? <div className="empty-state compact-empty">No available items.</div> : null}</div> : null}
            </div>
            {hasPermission('inventory-master', 'CREATE') ? <button className="secondary-button supplier-add-item-button" type="button" onClick={openNewItem}>Add Item</button> : null}
          </div>
        </div>

        <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving ? 'Saving...' : 'Save Supplier'}</button></div>
      </form>
    </Modal> : null}

    {itemForm ? <Modal title="Create New Inventory Item" subtitle="The new item will be added to this supplier automatically." onClose={() => setItemForm(null)} wide className="inventory-item-modal">
      <form className="form-grid product-master-form supplier-new-item-form-v2 inventory-product-form-v3" onSubmit={saveNewItem}>
        <div className="product-master-section-heading form-span-2"><strong>Item Identity</strong><span>Same item setup used in Inventory Masterlist.</span></div>
        <label className="form-field"><span>SKU Code</span><input value={itemForm.sku} readOnly placeholder="Auto-generated on save" className="readonly-input" /></label>
        <label className="form-field"><span>Barcode / GTIN</span><input value={itemForm.barcode} onChange={(e) => setItemForm({ ...itemForm, barcode: e.target.value })} placeholder="Optional scanner barcode" /></label>
        <label className="form-field product-status-field"><span>Status</span><SearchableSelect value={itemForm.status} onChange={(e) => setItemForm({ ...itemForm, status: e.target.value })}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
        <label className="form-field product-item-name-field"><span>Item Name</span><input required value={itemForm.name} onChange={(e) => setItemForm({ ...itemForm, name: e.target.value })} /></label>
        <label className="form-field"><span>Brand</span><input value={itemForm.brand} onChange={(e) => setItemForm({ ...itemForm, brand: e.target.value })} placeholder="Enter brand (optional)" /></label>

        <div className="product-master-section-heading form-span-2"><strong>Classification</strong><span>Category choices follow the selected Stock Type.</span></div>
        <label className="form-field"><span>Stock Type</span><SearchableSelect value={itemForm.stock_type} onChange={(e) => changeItemStockType(e.target.value)}><option value="INVENTORY">Inventory</option><option value="NON_INVENTORY">Non-inventory</option><option value="ASSET">Asset</option></SearchableSelect></label>
        <label className="form-field"><span>Category</span><SearchableSelect value={itemForm.category} onChange={(e) => setItemForm({ ...itemForm, category: e.target.value })}><option value="">Select category</option>{itemCategories.map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field product-retail-control"><span>Sellable</span><SearchableSelect value={itemForm.stock_type === 'INVENTORY' && itemForm.retail_available ? 'YES' : 'NO'} disabled={itemForm.stock_type !== 'INVENTORY'} onChange={(e) => { const yes = e.target.value === 'YES'; setItemForm({ ...itemForm, retail_available: yes, commission_eligible: yes ? itemForm.commission_eligible : false }) }}><option value="YES">Yes</option><option value="NO">No</option></SearchableSelect></label>

        <label className="form-field"><span>Purchase UOM</span><SearchableSelect value={itemForm.purchase_uom} onChange={(e) => setItemForm({ ...itemForm, purchase_uom: e.target.value })}>{uoms.map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Basic UOM</span><SearchableSelect value={itemForm.unit} onChange={(e) => setItemForm({ ...itemForm, unit: e.target.value })}>{uoms.map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Purchase Conversion</span><input type="number" min="0.000001" step="0.000001" value={itemForm.purchase_to_basic_factor} onChange={(e) => setItemForm({ ...itemForm, purchase_to_basic_factor: e.target.value })} /><span className="field-help">1 {itemForm.purchase_uom} = {itemForm.purchase_to_basic_factor} {itemForm.unit}</span></label>

        {itemForm.stock_type === 'INVENTORY' && itemForm.retail_available ? <>
          <label className="form-field"><span>Selling UOM</span><SearchableSelect value={itemForm.selling_uom} onChange={(e) => setItemForm({ ...itemForm, selling_uom: e.target.value })}>{uoms.map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
          <label className="form-field product-form-span-2-cols"><span>Selling Conversion</span><input type="number" min="0.000001" step="0.000001" value={itemForm.selling_to_basic_factor} onChange={(e) => setItemForm({ ...itemForm, selling_to_basic_factor: e.target.value })} /><span className="field-help">1 {itemForm.selling_uom} = {itemForm.selling_to_basic_factor} {itemForm.unit}</span></label>
        </> : null}

        <label className="form-field"><span>Consumption UOM</span><SearchableSelect value={itemForm.consumption_uom} onChange={(e) => setItemForm({ ...itemForm, consumption_uom: e.target.value })}>{uoms.map((row) => <option key={row.code} value={row.value}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field product-form-span-2-cols"><span>Consumption Conversion</span><input type="number" min="0.000001" step="0.000001" value={itemForm.consumption_to_basic_factor} onChange={(e) => setItemForm({ ...itemForm, consumption_to_basic_factor: e.target.value })} /><span className="field-help">1 {itemForm.unit} = {itemForm.consumption_to_basic_factor} {itemForm.consumption_uom}</span></label>

        <div className="product-master-section-heading form-span-2"><strong>Pricing & Inventory</strong><span>Purchase cost, operational cost, selling controls, and replenishment settings.</span></div>
        <label className="form-field"><span>Purchase UOM Cost</span><div className="inventory-cost-input-wrap"><input type="number" min="0" step="0.01" value={itemForm.purchase_uom_cost} onChange={(e) => setItemForm({ ...itemForm, purchase_uom_cost: e.target.value })} required /><span className="inventory-cost-uom-suffix">per {String(itemForm.purchase_uom || 'unit').toLowerCase()}</span></div></label>
        <label className="form-field inventory-readonly-field"><span>Consumption UOM Cost</span><div className="inventory-readonly-value">₱{derivedConsumptionCost(itemForm).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} / {itemForm.consumption_uom || 'consumption unit'}</div></label>
        <label className="form-field inventory-readonly-field"><span>Current Weighted Avg. Cost</span><div className="inventory-readonly-value">₱{weightedConsumptionCost(itemForm).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} / {itemForm.consumption_uom || 'consumption unit'}</div></label>

        {itemForm.stock_type === 'INVENTORY' && itemForm.retail_available ? <>
          <label className="form-field"><span>Selling Price</span><input type="number" min="0" step="0.01" value={itemForm.selling_price} onChange={(e) => setItemForm({ ...itemForm, selling_price: e.target.value })} required /></label>
          <label className="form-field"><span>Selling Price Effectivity Date</span><input type="date" value={itemForm.selling_price_effective_from} onChange={(e) => setItemForm({ ...itemForm, selling_price_effective_from: e.target.value })} required /></label>
          <label className="form-field"><span>Reorder Point</span><input type="number" min="0" step="0.0001" value={itemForm.reorder_point} onChange={(e) => setItemForm({ ...itemForm, reorder_point: e.target.value })} /></label>
          <div className="product-commission-control form-span-2"><input id="supplierItemCommissionEligible" type="checkbox" checked={itemForm.commission_eligible} onChange={(e) => setItemForm({ ...itemForm, commission_eligible: e.target.checked })} /><label htmlFor="supplierItemCommissionEligible"><strong>Commission Eligible</strong><span>Allow assigned staff to earn a retail purchase commission when a matching rule exists.</span></label></div>
        </> : itemForm.stock_type === 'INVENTORY' ? <label className="form-field"><span>Reorder Point</span><input type="number" min="0" step="0.0001" value={itemForm.reorder_point} onChange={(e) => setItemForm({ ...itemForm, reorder_point: e.target.value })} /></label> : null}

        <label className="form-field form-span-2"><span>Notes</span><textarea rows="3" value={itemForm.notes} onChange={(e) => setItemForm({ ...itemForm, notes: e.target.value })} /></label>
        <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setItemForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving ? 'Saving...' : 'Create Item'}</button></div>
      </form>
    </Modal> : null}
  </div>
}
