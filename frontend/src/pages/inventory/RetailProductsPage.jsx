import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './inventory-update79.css'

const emptyForm = {
  id: null,
  sku: '',
  barcode: '',
  name: '',
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
  stock_type: 'INVENTORY',
  unit_cost: '0',
  selling_price: '0',
  selling_price_effective_from: '',
  reorder_point: '0',
  commission_eligible: true,
  status: 'ACTIVE',
  notes: '',
}

const stockTypeLabels = {
  INVENTORY: 'Inventory',
  NON_INVENTORY: 'Non-inventory',
  ASSET: 'Asset',
}

function normalizedOptions(options = []) {
  return options.map((option) => (
    typeof option === 'string'
      ? { code: option, value: option }
      : { code: option.code || option.value, value: option.value || option.display_value || option.name || option.code }
  ))
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

function uniqueOptions(groups = []) {
  const seen = new Set()
  return groups.flat().filter((option) => {
    const key = String(option?.value || '').trim().toLowerCase()
    if (!key || seen.has(key)) return false
    seen.add(key)
    return true
  })
}

export default function RetailProductsPage() {
  const { hasPermission } = useAuth()
  const [products, setProducts] = useState([])
  const [branches, setBranches] = useState([])
  const [categoryOptionsByStockType, setCategoryOptionsByStockType] = useState({
    INVENTORY: [],
    NON_INVENTORY: [],
    ASSET: [],
  })
  const [uomOptions, setUomOptions] = useState([])
  const [branchId, setBranchId] = useState('')
  const [itemOptions, setItemOptions] = useState([])
  const [itemId, setItemId] = useState('')
  const [category, setCategory] = useState('')
  const [stockType, setStockType] = useState('ALL')
  const [status, setStatus] = useState('ALL')
  const [retail, setRetail] = useState('ALL')
  const [commission, setCommission] = useState('ALL')
  const [form, setForm] = useState(null)
  const [viewProduct, setViewProduct] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const filterCategoryOptions = useMemo(() => {
    if (stockType === 'INVENTORY') return categoryOptionsByStockType.INVENTORY
    if (stockType === 'NON_INVENTORY') return categoryOptionsByStockType.NON_INVENTORY
    if (stockType === 'ASSET') return categoryOptionsByStockType.ASSET
    return uniqueOptions([
      categoryOptionsByStockType.INVENTORY,
      categoryOptionsByStockType.NON_INVENTORY,
      categoryOptionsByStockType.ASSET,
    ])
  }, [categoryOptionsByStockType, stockType])

  const formCategoryOptions = form
    ? categoryOptionsByStockType[form.stock_type] || []
    : []

  const load = useCallback(async (next = {}) => {
    setMessage('')
    const response = await api.get('/v1/inventory/products', {
      params: {
        branch_id: next.branch_id || undefined,
        product_id: next.product_id || undefined,
        category: next.category || undefined,
        stock_type: next.stock_type && next.stock_type !== 'ALL' ? next.stock_type : undefined,
        status: next.status && next.status !== 'ALL' ? next.status : undefined,
        retail: next.retail && next.retail !== 'ALL' ? next.retail : undefined,
        commission: next.commission && next.commission !== 'ALL' ? next.commission : undefined,
      },
    })

    const references = response.data.reference_options || {}
    const groupedCategories = references.categories_by_stock_type || {}

    setProducts(response.data.products || [])
    setBranches(response.data.branches || [])
    setCategoryOptionsByStockType({
      INVENTORY: normalizedOptions(groupedCategories.INVENTORY || references.product_categories || response.data.categories || []),
      NON_INVENTORY: normalizedOptions(groupedCategories.NON_INVENTORY || references.non_inventory_categories || []),
      ASSET: normalizedOptions(groupedCategories.ASSET || references.asset_categories || []),
    })
    setUomOptions(normalizedOptions(references.product_uoms || []))
    setItemOptions(references.item_options || [])

    if (!next.branch_id && response.data.selected_branch_id) {
      setBranchId(String(response.data.selected_branch_id))
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load({})
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load inventory masterlist.')))
        .finally(() => setLoading(false))
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  async function applyFilters(event) {
    event.preventDefault()
    setLoading(true)
    try {
      await load({ branch_id: branchId, product_id: itemId, category, stock_type: stockType, status, retail, commission })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load inventory masterlist.'))
    } finally {
      setLoading(false)
    }
  }

  async function resetFilters() {
    setItemId('')
    setCategory('')
    setStockType('ALL')
    setStatus('ALL')
    setRetail('ALL')
    setCommission('ALL')
    setLoading(true)
    try {
      await load({ branch_id: branchId, product_id: '', category: '', stock_type: 'ALL', status: 'ALL', retail: 'ALL', commission: 'ALL' })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load inventory masterlist.'))
    } finally {
      setLoading(false)
    }
  }

  function openCreate() {
    setMessage('')
    setForm({
      ...emptyForm,
      unit: uomOptions[0]?.value || 'pc',
      purchase_uom: uomOptions[0]?.value || 'pc',
      selling_uom: uomOptions[0]?.value || 'pc',
      consumption_uom: uomOptions[0]?.value || 'pc',
      selling_price_effective_from: today(),
    })
  }

  function editProduct(product) {
    setMessage('')
    setForm({
      id: product.id,
      sku: product.sku || '',
      barcode: product.barcode || '',
      name: product.name || '',
      category: product.category || '',
      brand: product.brand || '',
      unit: product.unit || uomOptions[0]?.value || 'pc',
      purchase_uom: product.purchase_uom || product.unit || uomOptions[0]?.value || 'pc',
      purchase_to_basic_factor: String(product.purchase_to_basic_factor ?? 1),
      purchase_uom_cost: String(product.purchase_uom_cost ?? (Number(product.unit_cost || 0) * Number(product.purchase_to_basic_factor || 1))),
      selling_uom: product.selling_uom || product.unit || uomOptions[0]?.value || 'pc',
      selling_to_basic_factor: String(product.selling_to_basic_factor ?? 1),
      consumption_uom: product.consumption_uom || product.unit || uomOptions[0]?.value || 'pc',
      consumption_to_basic_factor: String(product.consumption_to_basic_factor ?? 1),
      retail_available: Boolean(product.retail_available),
      stock_type: product.stock_type || 'INVENTORY',
      unit_cost: String(product.unit_cost ?? 0),
      selling_price: String(product.selling_price ?? 0),
      selling_price_effective_from: product.selling_price_effective_from || today(),
      reorder_point: String(product.reorder_point ?? 0),
      commission_eligible: Boolean(product.commission_eligible),
      status: product.requested_status || (product.status === 'PENDING_APPROVAL' ? 'ACTIVE' : product.status) || 'ACTIVE',
      notes: product.notes || '',
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      const tracked = form.stock_type === 'INVENTORY'
      const retailAvailable = tracked && Boolean(form.retail_available)
      const payload = {
        ...form,
        sku: form.sku || null,
        unit_cost: Number(derivedBasicCost(form).toFixed(4)),
        purchase_uom_cost: Number(form.purchase_uom_cost || 0),
        basic_uom_cost: Number(derivedBasicCost(form).toFixed(4)),
        purchase_to_basic_factor: Number(form.purchase_to_basic_factor || 1),
        selling_to_basic_factor: Number(form.selling_to_basic_factor || 1),
        consumption_to_basic_factor: Number(form.consumption_to_basic_factor || 1),
        retail_available: retailAvailable,
        selling_price: retailAvailable ? Number(form.selling_price || 0) : 0,
        reorder_point: tracked ? Number(form.reorder_point || 0) : 0,
        commission_eligible: retailAvailable ? Boolean(form.commission_eligible) : false,
      }

      if (form.id) {
        await api.put(`/v1/inventory/products/${form.id}`, payload)
      } else {
        await api.post('/v1/inventory/products', payload)
      }

      setForm(null)
      await load({ branch_id: branchId, product_id: itemId, category, stock_type: stockType, status, retail, commission })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save inventory item.'))
    } finally {
      setSaving(false)
    }
  }

  async function approveProduct(product) {
    setMessage('')
    try {
      await api.post(`/v1/inventory/products/${product.id}/approve`)
      await load({ branch_id: branchId, product_id: itemId, category, stock_type: stockType, status, retail, commission })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve inventory item.'))
    }
  }

  async function toggle(product) {
    setMessage('')
    try {
      await api.patch(`/v1/inventory/products/${product.id}/toggle`)
      await load({ branch_id: branchId, product_id: itemId, category, stock_type: stockType, status, retail, commission })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update inventory item status.'))
    }
  }

  function changeFilterStockType(nextStockType) {
    setStockType(nextStockType)
    setCategory('')
  }

  function changeFormStockType(nextStockType) {
    setForm((current) => ({
      ...current,
      stock_type: nextStockType,
      category: '',
      selling_price: nextStockType === 'INVENTORY' ? current.selling_price : '0',
      reorder_point: nextStockType === 'INVENTORY' ? current.reorder_point : '0',
      retail_available: nextStockType === 'INVENTORY' ? current.retail_available : false,
      commission_eligible: nextStockType === 'INVENTORY' ? current.commission_eligible : false,
    }))
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">
            Inventory Masterlist
            <PageRefreshButton label="Refresh inventory masterlist" disabled={loading || saving} onRefresh={() => load({ branch_id: branchId, product_id: itemId, category, stock_type: stockType, status, retail, commission })} />
          </h1>
          <p className="page-subtitle">Central masterlist for sellable inventory, internal-use supplies, and controlled assets.</p>
        </div>
        {hasPermission('inventory-master', 'CREATE') ? (
          <button className="primary-button" type="button" onClick={openCreate}>Add Item</button>
        ) : null}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <form className="booking-filter-panel retail-filter-panel" onSubmit={applyFilters}>
          <label className="booking-filter-field retail-master-search">
            Item Name
            <SearchableSelect value={itemId} onChange={(event) => setItemId(event.target.value)}>
              <option value="">All Items</option>
              {itemOptions.map((item) => <option key={item.id} value={item.id}>{item.name || item.label}</option>)}
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-branch">
            Branch
            <SearchableSelect value={branchId} onChange={(event) => setBranchId(event.target.value)}>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-stock-type">
            Stock Type
            <SearchableSelect value={stockType} onChange={(event) => changeFilterStockType(event.target.value)}>
              <option value="ALL">All Types</option>
              <option value="INVENTORY">Inventory</option>
              <option value="NON_INVENTORY">Non-inventory</option>
              <option value="ASSET">Asset</option>
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-category">
            Category
            <SearchableSelect value={category} onChange={(event) => setCategory(event.target.value)}>
              <option value="">All Categories</option>
              {filterCategoryOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>)}
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-retail">
            Retail
            <SearchableSelect value={retail} onChange={(event) => setRetail(event.target.value)}>
              <option value="ALL">All</option>
              <option value="YES">Yes</option>
              <option value="NO">No</option>
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-commission">
            Commission
            <SearchableSelect value={commission} onChange={(event) => setCommission(event.target.value)}>
              <option value="ALL">All</option>
              <option value="YES">Eligible</option>
              <option value="NO">Not Eligible</option>
            </SearchableSelect>
          </label>
          <label className="booking-filter-field retail-master-status">
            Status
            <SearchableSelect value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="ALL">All</option>
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
              <option value="PENDING_APPROVAL">Pending Approval</option>
            </SearchableSelect>
          </label>
          <div className="retail-filter-actions">
            <button className="primary-button compact-button" type="submit" disabled={loading}>Apply</button>
            <button className="secondary-button compact-button" type="button" onClick={resetFilters} disabled={loading}>Reset</button>
          </div>
        </form>

        <div className="table-wrap">
          <table className="data-table product-master-table">
            <thead>
              <tr>
                <th>SKU / Barcode</th>
                <th>Item</th>
                <th>Category / Brand</th>
                <th>Basic UOM</th>
                <th>Retail Price</th>
                <th>Commission</th>
                <th>Status</th>
                <th className="actions-column">Action</th>
              </tr>
            </thead>
            <tbody>
              {products.map((product) => {
                const tracked = product.stock_type === 'INVENTORY'
                return (
                  <tr key={product.id}>
                    <td><strong>{product.sku}</strong><span className="table-subline">{product.barcode || 'No barcode'}</span></td>
                    <td><strong>{product.name}</strong><span className="table-subline">{stockTypeLabels[product.stock_type] || 'Inventory'}</span></td>
                    <td>{product.category || '—'}<span className="table-subline">{product.brand || 'No brand'}</span></td>
                    <td>{product.unit || product.basic_uom || '—'}</td>
                    <td>{tracked && product.retail_available ? <strong>{formatPeso(product.selling_price)}</strong> : '—'}</td>
                    <td>{tracked && product.retail_available ? (product.commission_eligible ? <span className="status-pill success">Eligible</span> : <span className="status-pill neutral">No</span>) : '—'}</td>
                    <td><span className={`status-pill ${(product.display_status || product.status) === 'ACTIVE' ? 'success' : (product.display_status || product.status) === 'PENDING_APPROVAL' ? 'warning' : 'neutral'}`}>{String(product.display_status || product.status).replaceAll('_', ' ')}</span></td>
                    <td className="action-cell"><ActionMenu items={[
                      { label: 'View', onClick: () => setViewProduct(product) },
                      hasPermission('inventory-master', 'APPROVE') && product.approval_pending ? { label: 'Approve', onClick: () => approveProduct(product) } : null,
                      hasPermission('inventory-master', 'EDIT') ? { label: 'Edit', onClick: () => editProduct(product) } : null,
                      hasPermission('inventory-master', 'EDIT') && product.status !== 'PENDING_APPROVAL' ? {
                        label: product.status === 'ACTIVE' ? 'Deactivate' : 'Activate',
                        onClick: () => toggle(product),
                        disabled: product.status === 'ACTIVE' && Boolean(product.has_stock_balance),
                      } : null,
                    ]} /></td>
                  </tr>
                )
              })}
              {!products.length ? <tr><td colSpan="8"><div className="empty-state compact-empty">No inventory items found.</div></td></tr> : null}
            </tbody>
          </table>
        </div>
      </section>

      {form ? (
        <Modal title={form.id ? 'Edit Inventory Item' : 'Add Inventory Item'} onClose={() => setForm(null)} wide className="inventory-item-modal">
          <form className="form-grid product-master-form inventory-product-form-v3" onSubmit={save}>
            <div className="product-master-section-heading form-span-2">
              <strong>Item Identity</strong>
              <span>Core identifiers used by inventory, POS, and internal records.</span>
            </div>

            <label className="form-field">
              SKU Code
              <input value={form.sku} readOnly placeholder="Auto-generated on save" className="readonly-input" />
            </label>
            <label className="form-field">
              Barcode / GTIN
              <input value={form.barcode} onChange={(event) => setForm({ ...form, barcode: event.target.value })} placeholder="Optional scanner barcode" />
            </label>
            <label className="form-field product-status-field">
              Status
              <SearchableSelect value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
              </SearchableSelect>
            </label>

            <label className="form-field product-item-name-field">
              Item Name
              <input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
            </label>
            <label className="form-field">
              Brand
              <input value={form.brand} onChange={(event) => setForm({ ...form, brand: event.target.value })} placeholder="Enter brand (optional)" />
            </label>

            <div className="product-master-section-heading form-span-2">
              <strong>Classification</strong>
              <span>Category choices follow the selected Stock Type and are maintained under Administration → Reference Lists.</span>
            </div>

            <label className="form-field">
              Stock Type
              <SearchableSelect value={form.stock_type} onChange={(event) => changeFormStockType(event.target.value)}>
                <option value="INVENTORY">Inventory</option>
                <option value="NON_INVENTORY">Non-inventory</option>
                <option value="ASSET">Asset</option>
              </SearchableSelect>
            </label>
            <label className="form-field">
              Category
              <SearchableSelect value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
                <option value="">Select category</option>
                {formCategoryOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>)}
              </SearchableSelect>
            </label>
            <label className="form-field product-retail-control">
              Sellable
              <SearchableSelect
                value={form.stock_type === 'INVENTORY' && form.retail_available ? 'YES' : 'NO'}
                disabled={form.stock_type !== 'INVENTORY'}
                onChange={(event) => {
                  const yes = event.target.value === 'YES'
                  setForm({ ...form, retail_available: yes, commission_eligible: yes ? form.commission_eligible : false })
                }}
              >
                <option value="YES">Yes</option>
                <option value="NO">No</option>
              </SearchableSelect>
            </label>

            <label className="form-field">
              Purchase UOM
              <SearchableSelect value={form.purchase_uom} onChange={(event) => setForm({ ...form, purchase_uom: event.target.value })} required>
                {uomOptions.length ? uomOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>) : <option value="pc">pc</option>}
              </SearchableSelect>
            </label>
            <label className="form-field">
              Basic UOM
              <SearchableSelect value={form.unit} onChange={(event) => setForm({ ...form, unit: event.target.value })} required>
                {uomOptions.length ? uomOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>) : <option value="pc">pc</option>}
              </SearchableSelect>
            </label>
            <label className="form-field">
              Purchase Conversion
              <input type="number" min="0.000001" step="0.000001" value={form.purchase_to_basic_factor} onChange={(event) => setForm({ ...form, purchase_to_basic_factor: event.target.value })} required />
              <span className="field-help">1 {form.purchase_uom || 'purchase unit'} = {form.purchase_to_basic_factor || 1} {form.unit || 'basic unit'}</span>
            </label>

            {form.stock_type === 'INVENTORY' && form.retail_available ? (
              <>
                <label className="form-field">
                  Selling UOM
                  <SearchableSelect value={form.selling_uom} onChange={(event) => setForm({ ...form, selling_uom: event.target.value })} required>
                    {uomOptions.length ? uomOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>) : <option value="pc">pc</option>}
                  </SearchableSelect>
                </label>
                <label className="form-field product-form-span-2-cols">
                  Selling Conversion
                  <input type="number" min="0.000001" step="0.000001" value={form.selling_to_basic_factor} onChange={(event) => setForm({ ...form, selling_to_basic_factor: event.target.value })} required />
                  <span className="field-help">1 {form.selling_uom || 'selling unit'} = {form.selling_to_basic_factor || 1} {form.unit || 'basic unit'}</span>
                </label>
              </>
            ) : null}

            <label className="form-field">
              Consumption UOM
              <SearchableSelect value={form.consumption_uom} onChange={(event) => setForm({ ...form, consumption_uom: event.target.value })} required>
                {uomOptions.length ? uomOptions.map((option) => <option key={`${option.code}-${option.value}`} value={option.value}>{option.value}</option>) : <option value="pc">pc</option>}
              </SearchableSelect>
            </label>
            <label className="form-field product-form-span-2-cols">
              Consumption Conversion
              <input type="number" min="0.000001" step="0.000001" value={form.consumption_to_basic_factor} onChange={(event) => setForm({ ...form, consumption_to_basic_factor: event.target.value })} required />
              <span className="field-help">1 {form.unit || 'basic unit'} = {form.consumption_to_basic_factor || 1} {form.consumption_uom || 'consumption unit'}</span>
            </label>

            <div className="product-master-section-heading form-span-2">
              <strong>Pricing & Inventory</strong>
              <span>Purchase cost, operational cost, selling controls, and replenishment settings.</span>
            </div>

            <label className="form-field">
              Purchase UOM Cost
              <div className="inventory-cost-input-wrap">
                <input type="number" min="0" step="0.01" value={form.purchase_uom_cost} onChange={(event) => setForm({ ...form, purchase_uom_cost: event.target.value })} required />
                <span className="inventory-cost-uom-suffix">per {String(form.purchase_uom || 'unit').toLowerCase()}</span>
              </div>
            </label>
            <label className="form-field inventory-readonly-field">
              Consumption UOM Cost
              <div className="inventory-readonly-value">{formatPeso(derivedConsumptionCost(form))} / {form.consumption_uom || 'consumption unit'}</div>
            </label>
            <label className="form-field inventory-readonly-field">
              Current Weighted Avg. Cost
              <div className="inventory-readonly-value">{formatPeso(weightedConsumptionCost(form))} / {form.consumption_uom || 'consumption unit'}</div>
            </label>

            {form.stock_type === 'INVENTORY' && form.retail_available ? (
              <>
                <label className="form-field">
                  Selling Price
                  <input type="number" min="0" step="0.01" value={form.selling_price} onChange={(event) => setForm({ ...form, selling_price: event.target.value })} required />
                </label>
                <label className="form-field">
                  Selling Price Effectivity Date
                  <input type="date" value={form.selling_price_effective_from || ''} onChange={(event) => setForm({ ...form, selling_price_effective_from: event.target.value })} required />
                  {form.id ? <span className="field-help">Use a new date when scheduling a selling price change.</span> : null}
                </label>
                <label className="form-field">
                  Reorder Point
                  <input type="number" min="0" step="0.0001" value={form.reorder_point} onChange={(event) => setForm({ ...form, reorder_point: event.target.value })} required />
                </label>
                <div className="product-commission-control form-span-2">
                  <input id="productCommissionEligible" type="checkbox" checked={form.commission_eligible} onChange={(event) => setForm({ ...form, commission_eligible: event.target.checked })} />
                  <label htmlFor="productCommissionEligible">
                    <strong>Commission Eligible</strong>
                    <span>Allow assigned staff to earn a Retail Purchase commission when a matching rule exists.</span>
                  </label>
                </div>
              </>
            ) : form.stock_type === 'INVENTORY' ? (
              <label className="form-field">
                Reorder Point
                <input type="number" min="0" step="0.0001" value={form.reorder_point} onChange={(event) => setForm({ ...form, reorder_point: event.target.value })} required />
              </label>
            ) : null}

            <label className="form-field form-span-2">
              Notes
              <textarea rows="3" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
            </label>

            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Item'}</button>
            </div>
          </form>
        </Modal>
      ) : null}

      {viewProduct ? (
        <Modal title={viewProduct.name || 'Inventory Item'} subtitle={viewProduct.sku || ''} onClose={() => setViewProduct(null)} wide className="inventory-product-view-modal">
          <div className="inventory-product-view-grid">
            <div><span>Category</span><strong>{viewProduct.category || '—'}</strong></div>
            <div><span>Brand</span><strong>{viewProduct.brand || '—'}</strong></div>
            <div><span>Stock Type</span><strong>{stockTypeLabels[viewProduct.stock_type] || viewProduct.stock_type}</strong></div>
            <div><span>Basic UOM</span><strong>{viewProduct.unit || '—'}</strong></div>
            <div><span>Purchase UOM</span><strong>{viewProduct.purchase_uom || viewProduct.unit || '—'} × {viewProduct.purchase_to_basic_factor || 1}</strong></div>
            <div><span>Selling UOM</span><strong>{viewProduct.selling_uom || viewProduct.unit || '—'} × {viewProduct.selling_to_basic_factor || 1}</strong></div>
            <div><span>Consumption UOM</span><strong>1 {viewProduct.unit || 'basic unit'} = {viewProduct.consumption_to_basic_factor || 1} {viewProduct.consumption_uom || viewProduct.unit || 'consumption unit'}</strong></div>
            <div><span>Purchase UOM Cost</span><strong>{formatPeso(viewProduct.purchase_uom_cost || 0)}</strong></div>
            <div><span>Consumption UOM Cost</span><strong>{formatPeso(viewProduct.consumption_uom_cost || 0)} / {viewProduct.consumption_uom || viewProduct.unit || 'unit'}</strong></div>
            <div><span>Weighted Avg. Cost</span><strong>{formatPeso(viewProduct.weighted_average_consumption_cost ?? 0)} / {viewProduct.consumption_uom || viewProduct.unit || 'unit'}</strong></div>
            <div><span>Retail</span><strong>{viewProduct.retail_available ? 'Yes' : 'No'}</strong></div>
            <div><span>Current Retail Price</span><strong>{viewProduct.retail_available ? formatPeso(viewProduct.selling_price) : '—'}</strong></div>
            <div><span>Commission</span><strong>{viewProduct.commission_eligible ? 'Eligible' : 'No'}</strong></div>
            <div><span>Status</span><strong>{String(viewProduct.display_status || viewProduct.status).replaceAll('_', ' ')}</strong></div>
          </div>
          <div className="section-heading compact-section-heading inventory-price-history-heading"><div><h2>Selling Price History</h2><p>Effective-dated retail price changes are retained for audit and historical sales consistency.</p></div></div>
          <div className="table-wrap">
            <table className="data-table inventory-price-history-table">
              <thead><tr><th>Retail Price</th><th>Effective From</th><th>Effective Until</th><th>Status</th></tr></thead>
              <tbody>{(viewProduct.selling_price_history || []).map((price) => <tr key={price.id}><td><strong>{formatPeso(price.amount)}</strong></td><td>{price.effective_from || '—'}</td><td>{price.effective_until || 'Current'}</td><td><span className={`status-pill ${price.status === 'ACTIVE' ? 'success' : price.status === 'SCHEDULED' ? 'warning' : 'neutral'}`}>{price.status}</span></td></tr>)}{!(viewProduct.selling_price_history || []).length ? <tr><td colSpan="4"><div className="empty-state compact-empty">No selling price history.</div></td></tr> : null}</tbody>
            </table>
          </div>
          {viewProduct.notes ? <div className="inventory-detail-notes"><span>Notes</span><p>{viewProduct.notes}</p></div> : null}
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setViewProduct(null)}>Close</button></div>
        </Modal>
      ) : null}

    </div>
  )
}
