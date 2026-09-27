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
    discount_code: '',
    branch_id: '',
    personnel_ids: [],
    applies_to: 'ALL',
    customer_id: '',
    customer_type: '',
    min_lifetime_value: '',
    max_lifetime_value: '',
    last_visit_operator: '',
    last_visit_days_min: '',
    last_visit_days_max: '',
    item_scope: 'ALL',
    service_id: '',
    service_ids: [],
    service_category_reference_id: '',
    service_category_reference_ids: [],
    calculation_type: 'PERCENTAGE',
    rate_value: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
    priority: '0',
    status: 'ACTIVE',
    stackable: true,
    notes: '',
  }
}

function formFromRule(rule) {
  return {
    id: rule.id,
    rule_name: rule.rule_name || '',
    discount_code: rule.discount_code || '',
    branch_id: rule.branch?.id ? String(rule.branch.id) : '',
    personnel_ids: (rule.personnel_ids || []).map(String),
    applies_to: rule.applies_to || 'ALL',
    customer_id: rule.customer?.id ? String(rule.customer.id) : '',
    customer_type: rule.customer_type || '',
    min_lifetime_value: rule.min_lifetime_value == null ? '' : String(rule.min_lifetime_value),
    max_lifetime_value: rule.max_lifetime_value == null ? '' : String(rule.max_lifetime_value),
    last_visit_operator: rule.last_visit_operator || '',
    last_visit_days_min: rule.last_visit_days_min == null ? '' : String(rule.last_visit_days_min),
    last_visit_days_max: rule.last_visit_days_max == null ? '' : String(rule.last_visit_days_max),
    item_scope: rule.item_scope || 'ALL',
    service_id: rule.service?.id ? String(rule.service.id) : '',
    service_ids: (rule.service_ids || (rule.service?.id ? [rule.service.id] : [])).map(String),
    service_category_reference_id: rule.service_category?.id ? String(rule.service_category.id) : '',
    service_category_reference_ids: (rule.service_category_reference_ids || (rule.service_category?.id ? [rule.service_category.id] : [])).map(String),
    calculation_type: rule.calculation_type || 'PERCENTAGE',
    rate_value: String(rule.rate_value ?? ''),
    effective_from: rule.effective_from || '',
    effective_until: rule.effective_until || '',
    priority: String(rule.priority ?? 0),
    status: rule.status || 'ACTIVE',
    stackable: rule.stackable !== false,
    notes: rule.notes || '',
  }
}

function customerScope(rule) {
  if (rule.customer?.name) return rule.customer.name
  if (rule.customer_type) return rule.customer_type.replaceAll('_', ' ')
  return 'All Customers'
}

function itemScope(rule) {
  const services = rule.service_list || []
  const categories = rule.service_category_list || []
  const scoped = []
  if (services.length) scoped.push(services.map((row) => row.name).join(', '))
  if (categories.length) scoped.push(categories.map((row) => row.name).join(', '))
  if (scoped.length) return scoped.join(' + ')
  const labels = { SERVICE: 'All Services', PACKAGE: 'All Packages', PRODUCT: 'All Retail', ALL: 'All Chargeable' }
  return labels[rule.item_scope] || 'All Chargeable'
}

function rateText(rule) {
  const value = Number(rule.rate_value || 0).toLocaleString('en-PH', { maximumFractionDigits: 4 })
  return rule.calculation_type === 'FIXED' ? `₱${value}` : `${value}%`
}

function pesoCriteria(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits: 2 })}`
}

function customerCriteria(rule) {
  const parts = []

  if (rule.min_lifetime_value != null && rule.max_lifetime_value != null) {
    parts.push(`LTV ${pesoCriteria(rule.min_lifetime_value)}–${pesoCriteria(rule.max_lifetime_value)}`)
  } else if (rule.min_lifetime_value != null) {
    parts.push(`LTV ≥ ${pesoCriteria(rule.min_lifetime_value)}`)
  } else if (rule.max_lifetime_value != null) {
    parts.push(`LTV ≤ ${pesoCriteria(rule.max_lifetime_value)}`)
  }

  const minDays = Number(rule.last_visit_days_min || 0)
  const maxDays = Number(rule.last_visit_days_max || 0)
  if (rule.last_visit_operator === 'MORE_THAN') parts.push(`Last visit > ${minDays} day${minDays === 1 ? '' : 's'}`)
  if (rule.last_visit_operator === 'WITHIN') parts.push(`Last visit within ${maxDays} day${maxDays === 1 ? '' : 's'}`)
  if (rule.last_visit_operator === 'BETWEEN') parts.push(`Last visit ${minDays}–${maxDays} days ago`)
  if (rule.last_visit_operator === 'NEVER') parts.push('Never visited before current transaction')

  return parts.join(' · ')
}

export default function DiscountRulesPage() {
  const { hasPermission } = useAuth()
  const [rules, setRules] = useState([])
  const [options, setOptions] = useState({ services: [], customers: [], personnel: [], branches: [], service_categories: [], customer_types: [] })
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('ALL')
  const [form, setForm] = useState(null)
  const [message, setMessage] = useState('')
  const [formMessage, setFormMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/discount-rules')
    setRules(response.data.rules || [])
    setOptions(response.data.options || { services: [], customers: [], personnel: [], branches: [], service_categories: [], customer_types: [] })
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load promotion rules.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [load])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    return rules.filter((rule) => {
      if (statusFilter !== 'ALL' && rule.display_status !== statusFilter) return false
      if (!term) return true
      return `${rule.code} ${rule.discount_code} ${rule.rule_name || ''} ${customerScope(rule)} ${customerCriteria(rule)} ${itemScope(rule)} ${rule.branch?.name || ''}`.toLowerCase().includes(term)
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
      if (field === 'discount_code') next.discount_code = String(value || '').toUpperCase()
      if (field === 'applies_to') {
        if (value !== 'CUSTOMER') next.customer_id = ''
        if (value !== 'CUSTOMER_TYPE') next.customer_type = ''
      }
      if (field === 'last_visit_operator') {
        if (value !== 'MORE_THAN' && value !== 'BETWEEN') next.last_visit_days_min = ''
        if (value !== 'WITHIN' && value !== 'BETWEEN') next.last_visit_days_max = ''
      }
      if (field === 'item_scope' && value === 'PRODUCT') {
        next.service_id = ''
        next.service_ids = []
        next.service_category_reference_id = ''
        next.service_category_reference_ids = []
      }
      return next
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setFormMessage('')

    const payload = {
      rule_name: form.rule_name,
      discount_code: form.discount_code,
      branch_id: form.branch_id ? Number(form.branch_id) : null,
      personnel_ids: (form.personnel_ids || []).map(Number),
      applies_to: form.applies_to,
      customer_id: form.customer_id ? Number(form.customer_id) : null,
      customer_type: form.customer_type || null,
      min_lifetime_value: form.min_lifetime_value === '' ? null : Number(form.min_lifetime_value),
      max_lifetime_value: form.max_lifetime_value === '' ? null : Number(form.max_lifetime_value),
      last_visit_operator: form.last_visit_operator || null,
      last_visit_days_min: form.last_visit_days_min === '' ? null : Number(form.last_visit_days_min),
      last_visit_days_max: form.last_visit_days_max === '' ? null : Number(form.last_visit_days_max),
      item_scope: form.item_scope,
      service_id: null,
      service_ids: (form.service_ids || []).map(Number),
      service_category_reference_id: null,
      service_category_reference_ids: (form.service_category_reference_ids || []).map(Number),
      calculation_type: form.calculation_type,
      rate_value: Number(form.rate_value),
      effective_from: form.effective_from,
      effective_until: form.effective_until || null,
      priority: Number(form.priority || 0),
      status: form.status,
      stackable: Boolean(form.stackable),
      notes: form.notes,
    }

    try {
      if (form.id) await api.put(`/v1/admin/discount-rules/${form.id}`, payload)
      else await api.post('/v1/admin/discount-rules', payload)
      setForm(null)
      await load()
    } catch (error) {
      setFormMessage(getApiErrorMessage(error, 'Unable to save discount rule.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggle(rule) {
    setMessage('')
    try {
      await api.patch(`/v1/admin/discount-rules/${rule.id}/toggle`)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update discount rule.'))
    }
  }

  const serviceScopeEnabled = form?.item_scope !== 'PRODUCT'

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Promotion Rules <PageRefreshButton label="Refresh promotion rules" disabled={loading || saving} onRefresh={load} /></h1>
          <p className="page-subtitle">Flexible checkout discounts by customer, lifetime value, last visit, service, category, item type, and branch.</p>
        </div>
        {hasPermission('discount-rules', 'CREATE') ? <button className="primary-button" type="button" onClick={openCreate}>Add Rule</button> : null}
      </section>

      <div className="form-alert warning discount-rule-note">
        Customer scope may be combined with optional Lifetime Value and Last Visit criteria. Specific service → category → all. Branch specificity, then Priority, resolves ties. Multiple promotion codes may be stacked at checkout. Turn off Stackable on a rule when that promotion must be exclusive.
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <div className="booking-filter-panel discount-filter-panel">
          <label className="booking-filter-field booking-search-field">Search<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Code, rule, customer, service" /></label>
          <label className="booking-filter-field">Status<SearchableSelect value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="ALL">All</option><option value="ACTIVE">Active</option><option value="SCHEDULED">Scheduled</option><option value="EXPIRED">Expired</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
        </div>

        {loading ? <div className="empty-state">Loading promotion rules...</div> : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Rule</th><th>Code</th><th>Scope</th><th>Discount</th><th>Effective</th><th>Priority</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {filtered.map((rule) => (
                  <tr key={rule.id}>
                    <td><strong>{rule.rule_name}</strong><span className="table-subline">{rule.code}</span></td>
                    <td><strong>{rule.discount_code}</strong><span className="table-subline">{rule.branch?.name || 'All Branches'}</span></td>
                    <td><strong>{customerScope(rule)}</strong><span className="table-subline">{(rule.personnel_list||[]).length ? `Personnel: ${(rule.personnel_list||[]).map((row)=>row.name).join(', ')}` : 'All Personnel'}</span><span className="table-subline">{itemScope(rule)}</span>{customerCriteria(rule) ? <span className="table-subline">{customerCriteria(rule)}</span> : null}</td>
                    <td><strong>{rateText(rule)}</strong><span className="table-subline">{rule.calculation_type === 'FIXED' ? 'Fixed' : 'Percentage'}</span></td>
                    <td><strong>{formatDisplayDate(rule.effective_from)}</strong><span className="table-subline">{rule.effective_until ? `to ${formatDisplayDate(rule.effective_until)}` : 'Open-ended'}</span></td>
                    <td><strong>{rule.priority}</strong></td>
                    <td><span className={`status-pill ${rule.display_status === 'ACTIVE' ? 'success' : rule.display_status === 'SCHEDULED' ? 'warning' : 'neutral'}`}>{rule.display_status}</span></td>
                    <td className="action-cell"><ActionMenu items={[
                      hasPermission('discount-rules', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(rule) } : null,
                      hasPermission('discount-rules', 'TOGGLE') ? { label: rule.status === 'ACTIVE' ? 'Deactivate' : 'Activate', onClick: () => toggle(rule) } : null,
                    ]} /></td>
                  </tr>
                ))}
                {!filtered.length ? <tr><td colSpan="8"><div className="empty-state compact-empty">No promotion rules found.</div></td></tr> : null}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form ? (
        <Modal title={form.id ? 'Edit Promotion Rule' : 'Add Promotion Rule'} onClose={() => setForm(null)}>
          <form className="form-grid" onSubmit={save}>
            {formMessage ? <div className="form-alert error form-span-2">{formMessage}</div> : null}

            <label className="form-field">Rule Name<input value={form.rule_name} onChange={(event) => setField('rule_name', event.target.value)} required /></label>
            <label className="form-field">Promotion Code<input value={form.discount_code} onChange={(event) => setField('discount_code', event.target.value)} placeholder="SAVE20" maxLength="40" required /></label>

            <label className="form-field">Branch<SearchableSelect value={form.branch_id} onChange={(event) => setField('branch_id', event.target.value)}><option value="">All Branches</option>{options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
            <label className="form-field">Applies To<SearchableSelect value={form.applies_to} onChange={(event) => setField('applies_to', event.target.value)} required><option value="ALL">All Customers</option><option value="CUSTOMER_TYPE">Customer Type</option><option value="CUSTOMER">Specific Customer</option></SearchableSelect></label>

            {form.applies_to === 'CUSTOMER' ? <label className="form-field form-span-2">Customer<SearchableSelect value={form.customer_id} onChange={(event) => setField('customer_id', event.target.value)} required><option value="">Select customer</option>{options.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} · {customer.code}</option>)}</SearchableSelect></label> : null}
            {form.applies_to === 'CUSTOMER_TYPE' ? <label className="form-field form-span-2">Customer Type<SearchableSelect value={form.customer_type} onChange={(event) => setField('customer_type', event.target.value)} required><option value="">Select type</option>{options.customer_types.map((type) => <option key={type.id} value={type.code}>{type.name}</option>)}</SearchableSelect></label> : null}

            <div className="form-span-2"><CheckboxMultiSelect label="Personnel" options={options.personnel || []} values={form.personnel_ids} onChange={(values) => setField('personnel_ids', values.map(String))} allLabel="All Personnel" /></div>

            <label className="form-field">Minimum Lifetime Value<input type="number" min="0" step="0.01" value={form.min_lifetime_value} onChange={(event) => setField('min_lifetime_value', event.target.value)} placeholder="Any" /></label>
            <label className="form-field">Maximum Lifetime Value<input type="number" min="0" step="0.01" value={form.max_lifetime_value} onChange={(event) => setField('max_lifetime_value', event.target.value)} placeholder="Any" /></label>

            <label className="form-field">Last Visit<SearchableSelect value={form.last_visit_operator} onChange={(event) => setField('last_visit_operator', event.target.value)}><option value="">Any</option><option value="MORE_THAN">More Than X Days Ago</option><option value="WITHIN">Within X Days</option><option value="BETWEEN">Between X and Y Days Ago</option><option value="NEVER">Never Visited Before Current Transaction</option></SearchableSelect></label>
            {form.last_visit_operator === 'MORE_THAN' ? <label className="form-field">Days<input type="number" min="0" step="1" value={form.last_visit_days_min} onChange={(event) => setField('last_visit_days_min', event.target.value)} required /></label> : null}
            {form.last_visit_operator === 'WITHIN' ? <label className="form-field">Days<input type="number" min="0" step="1" value={form.last_visit_days_max} onChange={(event) => setField('last_visit_days_max', event.target.value)} required /></label> : null}
            {form.last_visit_operator === 'BETWEEN' ? <>
              <label className="form-field">Minimum Days Ago<input type="number" min="0" step="1" value={form.last_visit_days_min} onChange={(event) => setField('last_visit_days_min', event.target.value)} required /></label>
              <label className="form-field">Maximum Days Ago<input type="number" min="0" step="1" value={form.last_visit_days_max} onChange={(event) => setField('last_visit_days_max', event.target.value)} required /></label>
            </> : null}
            {form.last_visit_operator === 'NEVER' ? <div className="form-alert warning form-span-2">For checkout eligibility, YODAMEN checks the customer history before the current transaction. The service being checked out is not treated as a prior visit.</div> : null}

            <label className="form-field form-span-2">Item Scope<SearchableSelect value={form.item_scope} onChange={(event) => setField('item_scope', event.target.value)} required><option value="ALL">All Chargeable</option><option value="SERVICE">Services</option><option value="PACKAGE">Packages</option><option value="PRODUCT">Retail</option></SearchableSelect></label>
            {serviceScopeEnabled ? <div className="form-span-2 rule-scope-grid">
              <CheckboxMultiSelect label="Service Categories" options={options.service_categories} values={form.service_category_reference_ids} onChange={(values) => setField('service_category_reference_ids', values.map(String))} />
              <CheckboxMultiSelect label="Services" options={options.services} values={form.service_ids} onChange={(values) => setField('service_ids', values.map(String))} />
            </div> : null}
            <label className="form-field">Method<SearchableSelect value={form.calculation_type} onChange={(event) => setField('calculation_type', event.target.value)} required><option value="PERCENTAGE">Percentage</option><option value="FIXED">Fixed Amount</option></SearchableSelect></label>

            <label className="form-field">{form.calculation_type === 'FIXED' ? 'Fixed Amount' : 'Percentage Rate'}<input type="number" min="0.0001" max={form.calculation_type === 'PERCENTAGE' ? '100' : undefined} step="0.0001" value={form.rate_value} onChange={(event) => setField('rate_value', event.target.value)} required /></label>
            <label className="form-field">Priority<input type="number" step="1" value={form.priority} onChange={(event) => setField('priority', event.target.value)} /></label>
            <label className="form-field checkbox-field"><span>Stackable with other promotions</span><input type="checkbox" checked={Boolean(form.stackable)} onChange={(event) => setField('stackable', event.target.checked)} /></label>

            <label className="form-field">Effective From<input type="date" value={form.effective_from} onChange={(event) => setField('effective_from', event.target.value)} required /></label>
            <label className="form-field">Effective Until<input type="date" value={form.effective_until} onChange={(event) => setField('effective_until', event.target.value)} min={form.effective_from || undefined} /></label>

            <label className="form-field">Status<SearchableSelect value={form.status} onChange={(event) => setField('status', event.target.value)}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
            <div />

            <label className="form-field form-span-2">Notes<textarea rows="2" value={form.notes} onChange={(event) => setField('notes', event.target.value)} /></label>

            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Rule'}</button></div>
          </form>
        </Modal>
      ) : null}
    </div>
  )
}
