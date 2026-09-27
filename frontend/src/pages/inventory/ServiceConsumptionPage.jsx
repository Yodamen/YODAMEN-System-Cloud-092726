import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './service-consumption.css'


function formatConsumptionDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  const datePart = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
  const timePart = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
  return `${datePart}, ${timePart}`
}

function emptyLine() {
  return { retail_product_id: '', quantity_per_service: '1' }
}

function emptyForm() {
  return { id: null, service_id: '', status: 'ACTIVE', notes: '', lines: [emptyLine()] }
}

export default function ServiceConsumptionPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ services: [], items: [], recipes: [], consumptions: [] })
  const [filters, setFilters] = useState({ service_id: '', status: 'ALL' })
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [selectedConsumption, setSelectedConsumption] = useState(null)

  const canCreate = hasPermission('service-consumption', 'CREATE')
  const canEdit = hasPermission('service-consumption', 'EDIT')

  const load = useCallback(async () => {
    const response = await api.get('/v1/inventory/service-consumption/recipes')
    setData(response.data)
  }, [])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load service material recipes.'))).finally(() => setLoading(false))
  }, [load])

  const visibleRecipes = useMemo(() => (data.recipes || []).filter((recipe) => {
    if (filters.service_id && String(recipe.service_id) !== String(filters.service_id)) return false
    if (filters.status !== 'ALL' && recipe.status !== filters.status) return false
    return true
  }), [data.recipes, filters])

  const servicesWithoutRecipe = useMemo(() => {
    const used = new Set((data.recipes || []).map((recipe) => Number(recipe.service_id)))
    return (data.services || []).filter((service) => !used.has(Number(service.id)))
  }, [data.recipes, data.services])

  const itemById = useMemo(() => new Map((data.items || []).map((item) => [String(item.id), item])), [data.items])
  const selectedRecipeService = useMemo(() => {
    if (!form?.service_id) return null
    return (data.services || []).find((service) => String(service.id) === String(form.service_id)) || null
  }, [data.services, form?.service_id])

  const selectedRecipeIsMultiSession = Boolean(selectedRecipeService)
    && (String(selectedRecipeService.service_count || '').toUpperCase() === 'MULTI_SESSION'
      || Number(selectedRecipeService.number_of_sessions || 1) > 1)
  const selectedRecipeSessions = selectedRecipeIsMultiSession
    ? Math.max(1, Number(selectedRecipeService?.number_of_sessions || 1))
    : 1


  function openCreate() {
    const initial = emptyForm()
    initial.service_id = servicesWithoutRecipe[0]?.id ? String(servicesWithoutRecipe[0].id) : ''
    setForm(initial)
  }

  function openEdit(recipe) {
    setForm({
      id: recipe.id,
      service_id: String(recipe.service_id),
      status: recipe.status,
      notes: recipe.notes || '',
      lines: (recipe.lines || []).map((line) => ({ retail_product_id: String(line.retail_product_id), quantity_per_service: String(line.quantity_per_service) })),
    })
  }

  function updateLine(index, patch) {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line),
    }))
  }

  function addLine() {
    setForm((current) => ({ ...current, lines: [...current.lines, emptyLine()] }))
  }

  function removeLine(index) {
    setForm((current) => ({ ...current, lines: current.lines.filter((_, lineIndex) => lineIndex !== index) }))
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const payload = {
        service_id: Number(form.service_id),
        status: form.status,
        notes: form.notes || null,
        lines: form.lines.map((line) => ({
          retail_product_id: Number(line.retail_product_id),
          quantity_per_service: Number(line.quantity_per_service),
        })),
      }
      if (form.id) await api.put(`/v1/inventory/service-consumption/recipes/${form.id}`, payload)
      else await api.post('/v1/inventory/service-consumption/recipes', payload)
      setForm(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save service material recipe.'))
    } finally {
      setSaving(false)
    }
  }

  const formEstimatedCost = useMemo(() => {
    if (!form) return 0
    return form.lines.reduce((total, line) => {
      const item = itemById.get(String(line.retail_product_id))
      return total + Number(line.quantity_per_service || 0) * Number(item?.weighted_average_consumption_cost ?? item?.weighted_average_cost ?? 0)
    }, 0)
  }, [form, itemById])

  return <div className="page-stack">
    <section className="page-heading live-page-heading">
      <div><h1 className="live-page-title">Service Consumption <PageRefreshButton label="Refresh service recipes" disabled={loading || saving} onRefresh={load} /></h1><p>Active Service Material Recipes are deducted automatically when a service is completed.</p></div>
      {canCreate && servicesWithoutRecipe.length ? <button className="primary-button" type="button" onClick={openCreate}>Add Service Recipe</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="content-card">
      <div className="service-recipe-filters">
        <label className="form-field"><span>Service</span><SearchableSelect value={filters.service_id} onChange={(event) => setFilters((current) => ({ ...current, service_id: event.target.value }))}><option value="">All Services</option>{(data.services || []).map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}><option value="ALL">All Statuses</option><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
      </div>
    </section>

    <section className="content-card">
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Service</th><th>Category</th><th>Materials</th><th>Est. Material Cost</th><th>Status</th><th>Last Updated</th><th className="actions-column">Action</th></tr></thead><tbody>
        {visibleRecipes.map((recipe) => <tr key={recipe.id}><td><strong>{recipe.service?.name}</strong><span className="table-subline">{recipe.service?.code}</span></td><td>{recipe.service?.category || '—'}</td><td>{recipe.materials_count}</td><td><strong>{formatPeso(recipe.estimated_material_cost || 0)}</strong></td><td><span className={`status-pill ${recipe.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{recipe.status}</span></td><td>{recipe.updated_at ? new Date(recipe.updated_at).toLocaleString('en-PH') : '—'}</td><td className="action-cell"><ActionMenu items={[canEdit ? { label: 'Edit Recipe', onClick: () => openEdit(recipe) } : null]} /></td></tr>)}
        {!loading && !visibleRecipes.length ? <tr><td colSpan="7"><div className="empty-state">No service material recipes found.</div></td></tr> : null}
      </tbody></table></div>
    </section>

    <section className="content-card">
      <div className="section-heading compact-section-heading"><div><h2>Consumption History</h2><p>Posted automatically from completed services. Negative stock is allowed and remains visible until later receipts or counts offset the deficit.</p></div></div>
      <div className="table-wrap"><table className="data-table service-consumption-history-table"><thead><tr><th>Booking ID</th><th>Date / Time</th><th>Service</th><th>Status</th><th>Branch</th><th>Materials</th><th>Total Cost</th><th>Posted By</th><th className="actions-column">Action</th></tr></thead><tbody>
        {(data.consumptions || []).map((row) => {
          const displayStatus = row.display_status || 'CONSUMED'
          const statusTone = displayStatus === 'REVERSED' ? 'danger' : displayStatus === 'PENDING' ? 'warning' : 'success'
          return <tr key={row.id}><td><strong>{row.booking_code || 'Direct / Unlinked'}</strong><span className="table-subline">{row.code}</span></td><td>{formatConsumptionDateTime(row.posted_at)}</td><td><strong>{row.service_name}</strong>{row.has_negative_stock ? <span className="table-subline danger-text">Negative stock posted</span> : null}</td><td><span className={`status-pill ${statusTone}`}>{displayStatus}</span>{row.pending_action ? <span className="table-subline">{row.pending_action.action_type.replaceAll('_', ' ')} · {row.pending_action.code}</span> : null}</td><td>{row.branch?.name || '—'}</td><td>{row.materials_count}</td><td><strong>{formatPeso(row.total_cost || 0)}</strong></td><td>{row.posted_by || '—'}</td><td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => setSelectedConsumption(row) }]} /></td></tr>
        })}
        {!loading && !(data.consumptions || []).length ? <tr><td colSpan="9"><div className="empty-state">No automatic service consumption has been posted yet.</div></td></tr> : null}
      </tbody></table></div>
    </section>

    {selectedConsumption ? <Modal title={selectedConsumption.booking_code || 'Direct / Unlinked'} subtitle={`${selectedConsumption.code} · ${selectedConsumption.service_name} · ${selectedConsumption.branch?.name || 'Branch'}`} onClose={() => setSelectedConsumption(null)} wide>
      <div className="service-consumption-detail-summary">
        <div><span>Posted</span><strong>{formatConsumptionDateTime(selectedConsumption.posted_at)}</strong></div>
        <div><span>Service Qty</span><strong>{selectedConsumption.service_quantity}</strong></div>
        <div><span>Status</span><strong>{selectedConsumption.display_status || 'CONSUMED'}</strong></div>
        <div><span>Materials</span><strong>{selectedConsumption.materials_count}</strong></div>
        <div><span>Total Cost</span><strong>{formatPeso(selectedConsumption.total_cost || 0)}</strong></div>
      </div>
      <div className="table-wrap"><table className="data-table service-consumption-detail-table"><thead><tr><th>Item</th><th>Consumed</th><th>Basic Qty</th><th>Weighted Cost</th><th>Cost</th><th>Before</th><th>After</th></tr></thead><tbody>
        {(selectedConsumption.lines || []).map((line) => <tr key={line.id}><td><strong>{line.item_name}</strong></td><td>{line.consumption_quantity} {line.consumption_uom}</td><td>{line.basic_quantity} {line.basic_uom}</td><td>{formatPeso(line.weighted_average_basic_cost)} / {line.basic_uom}</td><td><strong>{formatPeso(line.cost_amount)}</strong></td><td>{line.quantity_before} {line.basic_uom}</td><td><strong className={line.negative_stock ? 'danger-text' : undefined}>{line.quantity_after} {line.basic_uom}</strong>{line.negative_stock ? <span className="table-subline danger-text">Deficit {line.stock_deficit} {line.basic_uom}</span> : null}</td></tr>)}
      </tbody></table></div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setSelectedConsumption(null)}>Close</button></div>
    </Modal> : null}

    {form ? <Modal
      title={form.id ? 'Edit Service Material Recipe' : 'Add Service Material Recipe'}
      subtitle={selectedRecipeIsMultiSession
        ? `Enter the TOTAL material recipe for the full ${selectedRecipeSessions}-session package. Each completed session automatically consumes 1/${selectedRecipeSessions} of the saved recipe.`
        : "Enter the material recipe for one completed service. Quantity is converted automatically to Basic UOM."}
      onClose={() => setForm(null)}
      wide
      className="service-recipe-modal"
    >
      <form className="form-grid service-recipe-form" onSubmit={save}>
        <label className="form-field"><span>Service</span><SearchableSelect required disabled={Boolean(form.id)} value={form.service_id} onChange={(event) => setForm((current) => ({ ...current, service_id: event.target.value }))}><option value="">Select service</option>{(form.id ? data.services : servicesWithoutRecipe).map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>

        <div className="form-span-2 service-recipe-materials">
          <div className="section-heading compact-section-heading"><div>
            <h2>{selectedRecipeIsMultiSession ? 'Materials for Full Package' : 'Materials per Completed Service'}</h2>
            <p>{selectedRecipeIsMultiSession
              ? `This service has ${selectedRecipeSessions} sessions. Enter the full-package material quantities here; posting divides them evenly across completed sessions.`
              : 'Use tracked Inventory items only. Retail and service-use SKUs can both be selected.'}</p>
          </div></div>
          <div className="service-recipe-line-header"><span>Item</span><span>Consumption UOM</span><span>{selectedRecipeIsMultiSession ? 'Qty / Full Package' : 'Qty / Service'}</span><span>Basic Qty</span><span>Weighted Cost</span><span>Est. Cost</span><span></span></div>
          {form.lines.map((line, index) => {
            const item = itemById.get(String(line.retail_product_id))
            const factor = Number(item?.basic_to_consumption_factor || item?.consumption_to_basic_factor || 1)
            const basicQty = Number(line.quantity_per_service || 0) / Math.max(factor, 0.000001)
            const consumptionCost = Number(item?.weighted_average_consumption_cost ?? item?.weighted_average_cost ?? 0)
            const estCost = Number(line.quantity_per_service || 0) * consumptionCost
            return <div className="service-recipe-line" key={`${index}-${line.retail_product_id}`}>
              <SearchableSelect required value={line.retail_product_id} onChange={(event) => updateLine(index, { retail_product_id: event.target.value })}><option value="">Select inventory item</option>{(data.items || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect>
              <div className="recipe-readonly">{item?.consumption_uom || item?.basic_uom || '—'}</div>
              <input type="number" min="0.0001" step="0.0001" required value={line.quantity_per_service} onChange={(event) => updateLine(index, { quantity_per_service: event.target.value })} />
              <div className="recipe-readonly">{Number.isFinite(basicQty) ? basicQty.toLocaleString('en-PH', { maximumFractionDigits: 4 }) : '0'} {item?.basic_uom || ''}</div>
              <div className="recipe-readonly">{formatPeso(item?.weighted_average_consumption_cost ?? item?.weighted_average_cost ?? 0)} / {item?.consumption_uom || ''}</div>
              <div className="recipe-readonly"><strong>{formatPeso(estCost)}</strong></div>
              <button className="secondary-button compact-button" type="button" disabled={form.lines.length === 1} onClick={() => removeLine(index)}>Remove</button>
            </div>
          })}
          <div className="service-recipe-material-actions">
            <button className="secondary-button compact-button" type="button" onClick={addLine}>Add Material</button>
            <div className="service-recipe-total">
              <span>{selectedRecipeIsMultiSession ? 'Full Package Recipe Cost' : 'Estimated Material Cost'}</span>
              <strong>{formatPeso(formEstimatedCost)}</strong>
              {selectedRecipeIsMultiSession ? <small>Per completed session: {formatPeso(formEstimatedCost / selectedRecipeSessions)}</small> : null}
            </div>
          </div>
        </div>

        <label className="form-field form-span-2"><span>Notes</span><textarea rows="3" value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} /></label>
        <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving ? 'Saving...' : 'Save Recipe'}</button></div>
      </form>
    </Modal> : null}
  </div>
}
