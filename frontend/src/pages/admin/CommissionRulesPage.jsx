import SearchableSelect from '../../components/common/SearchableSelect'
import CheckboxMultiSelect from '../../components/common/CheckboxMultiSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate } from '../../utils/formatters'

function emptyForm() {
  return {
    id: null,
    rule_name: '',
    branch_id: '',
    applies_to: 'ALL',
    commission_scope: 'SERVICE',
    personnel_id: '',
    personnel_ids: [],
    position_reference_id: '',
    service_id: '',
    service_ids: [],
    service_category_reference_id: '',
    service_category_reference_ids: [],
    retail_product_id: '',
    retail_category: '',
    calculation_type: 'PERCENTAGE',
    rate_value: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
    priority: '0',
    status: 'ACTIVE',
    notes: '',
  }
}

function formFromRule(rule) {
  return {
    id: rule.id,
    rule_name: rule.rule_name || '',
    branch_id: rule.branch?.id ? String(rule.branch.id) : '',
    applies_to: rule.applies_to || 'ALL',
    commission_scope: rule.commission_scope || 'SERVICE',
    personnel_id: rule.personnel?.id ? String(rule.personnel.id) : '',
    personnel_ids: (rule.personnel_ids || (rule.personnel?.id ? [rule.personnel.id] : [])).map(String),
    position_reference_id: rule.position?.id ? String(rule.position.id) : '',
    service_id: rule.service?.id ? String(rule.service.id) : '',
    service_ids: (rule.service_ids || (rule.service?.id ? [rule.service.id] : [])).map(String),
    service_category_reference_id: rule.service_category?.id ? String(rule.service_category.id) : '',
    service_category_reference_ids: (rule.service_category_reference_ids || (rule.service_category?.id ? [rule.service_category.id] : [])).map(String),
    retail_product_id: rule.retail_product?.id ? String(rule.retail_product.id) : '',
    retail_category: rule.retail_category || '',
    calculation_type: rule.calculation_type || 'PERCENTAGE',
    rate_value: String(rule.rate_value ?? ''),
    effective_from: rule.effective_from || '',
    effective_until: rule.effective_until || '',
    priority: String(rule.priority ?? 0),
    status: rule.status || 'ACTIVE',
    notes: rule.notes || '',
  }
}

function scopeText(rule) {
  const personnel = rule.personnel_list || []
  const services = rule.service_list || []
  const categories = rule.service_category_list || []
  const staff = rule.applies_to === 'EMPLOYEE'
    ? (personnel.length ? personnel.map((row) => row.name).join(', ') : (rule.personnel?.name || 'Selected Personnel'))
    : (rule.position?.name || 'All Eligible Staff')
  if (rule.commission_scope === 'RETAIL') return `${staff} · ${rule.retail_product?.name || rule.retail_category || 'All Retail Products'}`
  const serviceParts = []
  if (services.length) serviceParts.push(services.map((row) => row.name).join(', '))
  if (categories.length) serviceParts.push(categories.map((row) => row.name).join(', '))
  return `${staff} · ${serviceParts.join(' + ') || 'All Services'}`
}

function rateText(rule) {
  const value = Number(rule.rate_value || 0).toLocaleString('en-PH', { maximumFractionDigits: 4 })
  return rule.calculation_type === 'FIXED' ? `₱${value}` : `${value}%`
}

export default function CommissionRulesPage() {
  const { hasPermission } = useAuth()
  const [rules, setRules] = useState([])
  const [options, setOptions] = useState({ services: [], retail_products: [], retail_categories: [], personnel: [], branches: [], positions: [], service_categories: [] })
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('ALL')
  const [form, setForm] = useState(null)
  const [message, setMessage] = useState('')
  const [formMessage, setFormMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/commission-rules')
    setRules(response.data.rules || [])
    setOptions(response.data.options || { services: [], retail_products: [], retail_categories: [], personnel: [], branches: [], positions: [], service_categories: [] })
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load commission rules.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [load])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    return rules.filter((rule) => {
      if (statusFilter !== 'ALL' && rule.display_status !== statusFilter) return false
      if (!term) return true
      return `${rule.code} ${rule.rule_name || ''} ${scopeText(rule)} ${rule.branch?.name || ''}`.toLowerCase().includes(term)
    })
  }, [rules, search, statusFilter])

  function openCreate() {
    setMessage('')
    setFormMessage('')
    setForm(emptyForm())
  }

  function openEdit(rule) {
    setMessage('')
    setFormMessage('')
    setForm(formFromRule(rule))
  }

  function setField(field, value) {
    setForm((current) => {
      const next = { ...current, [field]: value }
      if (field === 'applies_to') {
        if (value !== 'EMPLOYEE') { next.personnel_id = ''; next.personnel_ids = [] }
        if (value !== 'POSITION') next.position_reference_id = ''
      }
      if (field === 'commission_scope') {
        if (value === 'RETAIL') { next.service_id = ''; next.service_ids = []; next.service_category_reference_id = ''; next.service_category_reference_ids = [] }
        else { next.retail_product_id = ''; next.retail_category = '' }
      }
      if (field === 'retail_product_id' && value) next.retail_category = ''
      if (field === 'retail_category' && value) next.retail_product_id = ''
      return next
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setFormMessage('')

    const payload = {
      rule_name: form.rule_name,
      branch_id: form.branch_id ? Number(form.branch_id) : null,
      applies_to: form.applies_to,
      commission_scope: form.commission_scope,
      personnel_id: null,
      personnel_ids: (form.personnel_ids || []).map(Number),
      position_reference_id: form.position_reference_id ? Number(form.position_reference_id) : null,
      service_id: null,
      service_ids: (form.service_ids || []).map(Number),
      service_category_reference_id: null,
      service_category_reference_ids: (form.service_category_reference_ids || []).map(Number),
      retail_product_id: form.retail_product_id ? Number(form.retail_product_id) : null,
      retail_category: form.retail_category || null,
      calculation_type: form.calculation_type,
      rate_value: Number(form.rate_value),
      effective_from: form.effective_from,
      effective_until: form.effective_until || null,
      priority: Number(form.priority || 0),
      status: form.status,
      notes: form.notes,
    }

    try {
      if (form.id) await api.put(`/v1/admin/commission-rules/${form.id}`, payload)
      else await api.post('/v1/admin/commission-rules', payload)
      setForm(null)
      await load()
    } catch (error) {
      setFormMessage(getApiErrorMessage(error, 'Unable to save commission rule.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggle(rule) {
    setMessage('')
    try {
      await api.patch(`/v1/admin/commission-rules/${rule.id}/toggle`)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update commission rule.'))
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Commission Rules <PageRefreshButton label="Refresh commission rules" disabled={loading || saving} onRefresh={load} /></h1>
          <p className="page-subtitle">Flexible service and retail commission rules by personnel, position, branch, service, category, or SKU.</p>
        </div>
        {hasPermission('commission-rules', 'CREATE') ? <button className="primary-button" type="button" onClick={openCreate}>Add Rule</button> : null}
      </section>


      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <div className="booking-filter-panel commission-filter-panel">
          <label className="booking-filter-field booking-search-field">Search<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Rule ID, name, staff, service" /></label>
          <label className="booking-filter-field">Status<SearchableSelect value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="ALL">All</option><option value="ACTIVE">Active</option><option value="SCHEDULED">Scheduled</option><option value="EXPIRED">Expired</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
        </div>

        {loading ? <div className="empty-state">Loading commission rules...</div> : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Rule</th><th>Scope</th><th>Rate</th><th>Branch</th><th>Effective</th><th>Priority</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {filtered.map((rule) => (
                  <tr key={rule.id}>
                    <td><strong>{rule.rule_name}</strong><span className="table-subline">{rule.code}</span></td>
                    <td><strong>{scopeText(rule)}</strong><span className="table-subline">{rule.specificity}</span></td>
                    <td><strong>{rateText(rule)}</strong><span className="table-subline">{rule.calculation_type === 'FIXED' ? 'Fixed Amount' : 'Percentage'}</span></td>
                    <td>{rule.branch?.name || 'All Branches'}</td>
                    <td><strong>{formatDisplayDate(rule.effective_from)}</strong><span className="table-subline">{rule.effective_until ? `to ${formatDisplayDate(rule.effective_until)}` : 'Open-ended'}</span></td>
                    <td><strong>{rule.priority}</strong></td>
                    <td><span className={`status-pill ${rule.display_status === 'ACTIVE' ? 'success' : rule.display_status === 'SCHEDULED' ? 'warning' : 'neutral'}`}>{rule.display_status}</span></td>
                    <td className="action-cell"><ActionMenu items={[
                      hasPermission('commission-rules', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(rule) } : null,
                      hasPermission('commission-rules', 'TOGGLE') ? { label: rule.status === 'ACTIVE' ? 'Deactivate' : 'Activate', onClick: () => toggle(rule) } : null,
                    ]} /></td>
                  </tr>
                ))}
                {!filtered.length ? <tr><td colSpan="8"><div className="empty-state compact-empty">No commission rules found.</div></td></tr> : null}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form ? (
        <Modal title={form.id ? 'Edit Commission Rule' : 'Add Commission Rule'} onClose={() => setForm(null)}>
          <form className="form-grid" onSubmit={save}>
            {formMessage ? <div className="form-alert error form-span-2">{formMessage}</div> : null}

            <label className="form-field form-span-2">Rule Name<input value={form.rule_name} onChange={(event) => setField('rule_name', event.target.value)} placeholder="e.g. Receptionist Service Commission" required /></label>

            <label className="form-field">Commission Scope<SearchableSelect value={form.commission_scope} onChange={(event) => setField('commission_scope', event.target.value)} required><option value="SERVICE">Services</option><option value="RETAIL">Retail Purchases</option></SearchableSelect></label>
            <label className="form-field">Branch<SearchableSelect value={form.branch_id} onChange={(event) => setField('branch_id', event.target.value)}><option value="">All Branches</option>{options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
            <label className="form-field">Applies To<SearchableSelect value={form.applies_to} onChange={(event) => setField('applies_to', event.target.value)} required><option value="ALL">All Eligible Staff</option><option value="POSITION">Position</option><option value="EMPLOYEE">Specific Personnel</option></SearchableSelect></label>

            {form.applies_to === 'EMPLOYEE' ? <div className="form-span-2"><CheckboxMultiSelect label="Personnel" options={options.personnel} values={form.personnel_ids} onChange={(values) => setField('personnel_ids', values.map(String))} getLabel={(person) => `${person.name}${person.position_name ? ` · ${person.position_name}` : ''}`} /></div> : null}
            {form.applies_to === 'POSITION' ? <label className="form-field form-span-2">Position<SearchableSelect value={form.position_reference_id} onChange={(event) => setField('position_reference_id', event.target.value)} required><option value="">Select position</option>{options.positions.map((position) => <option key={position.id} value={position.id}>{position.name}</option>)}</SearchableSelect></label> : null}

            {form.commission_scope === 'SERVICE' ? <div className="form-span-2 rule-scope-grid">
              <CheckboxMultiSelect label="Service Categories" options={options.service_categories} values={form.service_category_reference_ids} onChange={(values) => setField('service_category_reference_ids', values.map(String))} />
              <CheckboxMultiSelect label="Services" options={options.services} values={form.service_ids} onChange={(values) => setField('service_ids', values.map(String))} />
            </div> : <><label className="form-field">Specific Product / SKU<SearchableSelect value={form.retail_product_id} onChange={(event) => setField('retail_product_id', event.target.value)}><option value="">All Retail Products</option>{options.retail_products.map((product) => <option key={product.id} value={product.id}>{product.sku} · {product.name}</option>)}</SearchableSelect></label><label className="form-field">Product Category<SearchableSelect value={form.retail_category} onChange={(event) => setField('retail_category', event.target.value)} disabled={Boolean(form.retail_product_id)}><option value="">All Categories</option>{options.retail_categories.map((category) => <option key={category} value={category}>{category}</option>)}</SearchableSelect></label></>}

            <label className="form-field">Commission Method<SearchableSelect value={form.calculation_type} onChange={(event) => setField('calculation_type', event.target.value)} required><option value="PERCENTAGE">Percentage</option><option value="FIXED">Fixed Amount</option></SearchableSelect></label>
            <label className="form-field">{form.calculation_type === 'FIXED' ? 'Fixed Amount' : 'Percentage Rate'}<input type="number" min="0.0001" max={form.calculation_type === 'PERCENTAGE' ? '100' : undefined} step="0.0001" value={form.rate_value} onChange={(event) => setField('rate_value', event.target.value)} required /></label>

            <label className="form-field">Calculation Basis<input value={form.commission_scope === 'RETAIL' ? 'Net Retail Sale Value' : 'Standard Service Price'} disabled /></label>
            <label className="form-field">Priority<input type="number" step="1" value={form.priority} onChange={(event) => setField('priority', event.target.value)} /></label>

            <label className="form-field">Effective From<input type="date" value={form.effective_from} onChange={(event) => setField('effective_from', event.target.value)} required /></label>
            <label className="form-field">Effective Until<input type="date" value={form.effective_until} onChange={(event) => setField('effective_until', event.target.value)} min={form.effective_from || undefined} /></label>

            <label className="form-field">Status<SearchableSelect value={form.status} onChange={(event) => setField('status', event.target.value)}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
            <div />

            <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(event) => setField('notes', event.target.value)} /></label>

            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Rule'}</button></div>
          </form>
        </Modal>
      ) : null}
    </div>
  )
}
