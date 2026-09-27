import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import CustomerAudienceBuilder from './CustomerAudienceBuilder'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate } from '../../utils/formatters'

const emptyForm = {
  id: null,
  first_name: '',
  last_name: '',
  mobile_number: '',
  email: '',
  birthday: '',
  gender_code: '',
  address: '',
  lead_source_code: '',
  registration_date: new Date().toISOString().slice(0, 10),
  notes: '',
  is_test_customer: false,
}

const months = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

function peso(value) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
  }).format(Number(value || 0))
}

function daysSince(dateValue) {
  if (!dateValue) return null
  const value = new Date(dateValue)
  if (Number.isNaN(value.getTime())) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const date = new Date(value.getFullYear(), value.getMonth(), value.getDate())
  return Math.max(0, Math.floor((today - date) / 86400000))
}

function matchesLastVisit(customer, filter) {
  if (filter === 'ALL') return true
  const days = customer.days_since_last_visit ?? daysSince(customer.last_visit)
  if (filter === 'NEVER') return days == null
  if (days == null) return false
  return Number(days) > Number(filter)
}

function matchesLifetimeValue(value, filter) {
  const amount = Number(value || 0)
  if (filter === 'ALL') return true
  if (filter === '0-999') return amount < 1000
  if (filter === '1000-4999') return amount >= 1000 && amount < 5000
  if (filter === '5000-9999') return amount >= 5000 && amount < 10000
  if (filter === '10000-19999') return amount >= 10000 && amount < 20000
  if (filter === '20000-49999') return amount >= 20000 && amount < 50000
  if (filter === '50000+') return amount >= 50000
  return true
}

function PromoCodeDropdown({ value, options = [], onChange }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const rootRef = useRef(null)

  const selected = useMemo(
    () => options.find((option) => String(option.code) === String(value)) || null,
    [options, value],
  )

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase()
    if (!needle) return options
    return options.filter((option) => String(option.code || '').toLowerCase().includes(needle))
  }, [options, search])

  useEffect(() => {
    function handleOutside(event) {
      if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handleOutside)
    return () => document.removeEventListener('mousedown', handleOutside)
  }, [])

  function choose(code) {
    onChange(code)
    setOpen(false)
    setSearch('')
  }

  return (
    <div className={`customer-promo-dropdown${open ? ' open' : ''}`} ref={rootRef}>
      <button
        type="button"
        className="customer-promo-dropdown-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <span className={!selected ? 'placeholder' : ''}>{selected?.code || 'All Promos'}</span>
        {selected && !selected.active ? <span className="customer-promo-status-tag">Inactive</span> : null}
        <span className="customer-promo-dropdown-chevron" aria-hidden="true">▾</span>
      </button>

      {open ? (
        <div className="customer-promo-dropdown-menu" role="listbox">
          {options.length > 8 ? (
            <div className="customer-promo-dropdown-search">
              <input
                type="search"
                placeholder="Search promo code..."
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                autoFocus
              />
            </div>
          ) : null}

          <div className="customer-promo-dropdown-options">
            <button
              type="button"
              className={`customer-promo-dropdown-option${value ? '' : ' selected'}`}
              onClick={() => choose('')}
            >
              <span>All Promos</span>
            </button>

            {filtered.map((option) => (
              <button
                type="button"
                className={`customer-promo-dropdown-option${String(option.code) === String(value) ? ' selected' : ''}`}
                key={option.code}
                onClick={() => choose(option.code)}
              >
                <span className="customer-promo-option-code">{option.code}</span>
                {!option.active ? <span className="customer-promo-status-tag">Inactive</span> : null}
              </button>
            ))}

            {!filtered.length ? <div className="customer-promo-dropdown-empty">No promo codes found.</div> : null}
          </div>
        </div>
      ) : null}
    </div>
  )
}

export default function CustomersPage() {
  const { hasPermission, user } = useAuth()
  const [customers, setCustomers] = useState([])
  const [references, setReferences] = useState({ genders: [], lead_sources: [], promo_codes: [] })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ALL')
  const [customerClass, setCustomerClass] = useState('ALL')
  const [customerType, setCustomerType] = useState('ALL')
  const [birthMonth, setBirthMonth] = useState('ALL')
  const [lastVisit, setLastVisit] = useState('ALL')
  const [lifetimeValue, setLifetimeValue] = useState('ALL')
  const [promoCode, setPromoCode] = useState('')
  const [promoFilter, setPromoFilter] = useState(null)
  const [promoLoading, setPromoLoading] = useState(false)
  const [audienceOpen, setAudienceOpen] = useState(false)
  const [audienceOptions, setAudienceOptions] = useState(null)
  const [audienceDefinition, setAudienceDefinition] = useState(null)
  const [audienceCustomerIds, setAudienceCustomerIds] = useState(null)
  const [audienceSummary, setAudienceSummary] = useState(null)
  const [audienceApplying, setAudienceApplying] = useState(false)
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [formError, setFormError] = useState('')
  const [customerProfile, setCustomerProfile] = useState(null)
  const [profileLoading, setProfileLoading] = useState(false)

  const load = useCallback(async (activePromoCode = '') => {
    const params = activePromoCode.trim() ? { promo_code: activePromoCode.trim() } : undefined
    const response = await api.get('/v1/operations/customers', { params })
    setCustomers(response.data.customers || [])
    setReferences(response.data.references || { genders: [], lead_sources: [], promo_codes: [] })
    setPromoFilter(response.data.promo_filter || null)
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPromoLoading(Boolean(promoCode.trim()))
      load(promoCode)
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load customers.')))
        .finally(() => {
          setLoading(false)
          setPromoLoading(false)
        })
    }, promoCode.trim() ? 350 : 100)
    return () => window.clearTimeout(timer)
  }, [load, promoCode])

  const customerClassOptions = useMemo(() => {
    const values = new Set()
    customers.forEach((customer) => values.add(String(customer.customer_class || 'Unclassified').trim() || 'Unclassified'))
    return Array.from(values).sort((a, b) => a.localeCompare(b))
  }, [customers])

  const customerTypeOptions = useMemo(() => {
    const values = new Set()
    customers.forEach((customer) => {
      const value = String(customer.customer_type || customer.segment || '').trim()
      if (value) values.add(value)
    })
    return Array.from(values).sort((a, b) => a.localeCompare(b))
  }, [customers])

  const filteredCustomers = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return customers.filter((customer) => {
      const fullName = String(customer.full_name || `${customer.first_name || ''} ${customer.last_name || ''}`).trim().toLowerCase()
      const matchesSearch = !needle
        || fullName.includes(needle)
        || String(customer.code || '').toLowerCase().includes(needle)
        || String(customer.mobile_number || '').toLowerCase().includes(needle)
      const matchesStatus = status === 'ALL' || String(customer.status || '').toUpperCase() === status
      const matchesClass = customerClass === 'ALL' || String(customer.customer_class || 'Unclassified') === customerClass
      const resolvedType = String(customer.customer_type || customer.segment || '')
      const matchesType = customerType === 'ALL' || resolvedType === customerType
      const birthdayMonth = customer.birthday_month ?? (customer.birthday ? Number(String(customer.birthday).slice(5, 7)) : null)
      const matchesBirthMonth = birthMonth === 'ALL' || Number(birthdayMonth) === Number(birthMonth)
      const matchesAudience = audienceCustomerIds == null || audienceCustomerIds.has(Number(customer.id))
      return matchesSearch
        && matchesStatus
        && matchesClass
        && matchesType
        && matchesBirthMonth
        && matchesLastVisit(customer, lastVisit)
        && matchesLifetimeValue(customer.lifetime_value, lifetimeValue)
        && matchesAudience
    })
  }, [audienceCustomerIds, birthMonth, customerClass, customerType, customers, lastVisit, lifetimeValue, search, status])

  async function refreshPageData() {
    setMessage('')
    try {
      await load(promoCode)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh customers.'))
    }
  }

  async function openAudienceBuilder() {
    setMessage('')
    try {
      if (!audienceOptions) {
        const response = await api.get('/v1/operations/customers/audience-options')
        setAudienceOptions(response.data.options || {})
      }
      setAudienceOpen(true)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Smart Audience options.'))
    }
  }

  async function applyAudience(definition) {
    setAudienceApplying(true)
    setMessage('')
    try {
      const response = await api.post('/v1/operations/customers/audience-preview', definition)
      setAudienceDefinition(definition)
      setAudienceCustomerIds(new Set((response.data.customer_ids || []).map(Number)))
      setAudienceSummary({
        matched_count: Number(response.data.matched_count || 0),
        total_customers: Number(response.data.total_customers || 0),
        groups: definition.groups?.length || 0,
        conditions: (definition.groups || []).reduce((sum, group) => sum + (group.conditions?.length || 0), 0),
      })
      setAudienceOpen(false)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to apply Smart Audience.'))
    } finally {
      setAudienceApplying(false)
    }
  }

  function clearAudience() {
    setAudienceDefinition(null)
    setAudienceCustomerIds(null)
    setAudienceSummary(null)
  }

  function resetFilters() {
    setSearch('')
    setStatus('ALL')
    setCustomerClass('ALL')
    setCustomerType('ALL')
    setBirthMonth('ALL')
    setLastVisit('ALL')
    setLifetimeValue('ALL')
    setPromoCode('')
    setPromoFilter(null)
    clearAudience()
  }

  function openCreate() {
    setMessage('')
    setFormError('')
    setForm({ ...emptyForm })
  }

  function openEdit(customer) {
    setMessage('')
    setFormError('')
    setForm({
      id: customer.id,
      first_name: customer.first_name || '',
      last_name: customer.last_name || '',
      mobile_number: customer.mobile_number || '',
      email: customer.email || '',
      birthday: customer.birthday || '',
      gender_code: customer.gender_code || '',
      address: customer.address || '',
      lead_source_code: customer.lead_source_code || '',
      registration_date: customer.registration_date || '',
      notes: customer.notes || '',
      is_test_customer: Boolean(customer.is_test_customer),
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setFormError('')
    try {
      const payload = { ...form }
      delete payload.id
      if (form.id) await api.put(`/v1/operations/customers/${form.id}`, payload)
      else await api.post('/v1/operations/customers', payload)
      setForm(null)
      await load(promoCode)
    } catch (error) {
      setFormError(getApiErrorMessage(error, 'Unable to save customer.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggle(customer) {
    setMessage('')
    try {
      await api.patch(`/v1/operations/customers/${customer.id}/toggle`)
      await load(promoCode)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to change customer status.'))
    }
  }

  async function openCustomerProfile(customer) {
    setProfileLoading(true)
    setMessage('')
    try {
      const response = await api.get(`/v1/operations/customers/${customer.id}`)
      setCustomerProfile(response.data)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load customer profile.'))
    } finally {
      setProfileLoading(false)
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Customers <PageRefreshButton label="Refresh customers" disabled={loading || saving} onRefresh={refreshPageData} /></h1>
        {hasPermission('customers', 'CREATE') && <button className="primary-button" type="button" onClick={openCreate}>+ Add Customer</button>}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card customer-list-card">
        <div className="customer-filter-panel">
          <label className="customer-filter-field customer-search-field">
            <span>Search</span>
            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, mobile or customer ID" />
          </label>
          <label className="customer-filter-field">
            <span>Status</span>
            <SearchableSelect value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="ALL">All</option>
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
            </SearchableSelect>
          </label>
          <label className="customer-filter-field">
            <span>Class</span>
            <SearchableSelect value={customerClass} onChange={(event) => setCustomerClass(event.target.value)}>
              <option value="ALL">All Classes</option>
              {customerClassOptions.map((value) => <option key={value} value={value}>{value}</option>)}
            </SearchableSelect>
          </label>
          <label className="customer-filter-field">
            <span>Type</span>
            <SearchableSelect value={customerType} onChange={(event) => setCustomerType(event.target.value)}>
              <option value="ALL">All Types</option>
              {customerTypeOptions.map((value) => <option key={value} value={value}>{value}</option>)}
            </SearchableSelect>
          </label>
          <label className="customer-filter-field">
            <span>Birth Month</span>
            <SearchableSelect value={birthMonth} onChange={(event) => setBirthMonth(event.target.value)}>
              <option value="ALL">All Months</option>
              {months.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}
            </SearchableSelect>
          </label>
          <label className="customer-filter-field">
            <span>Last Visit</span>
            <SearchableSelect value={lastVisit} onChange={(event) => setLastVisit(event.target.value)}>
              <option value="ALL">All</option>
              <option value="14">Over 14 Days</option>
              <option value="21">Over 21 Days</option>
              <option value="30">Over 30 Days</option>
              <option value="45">Over 45 Days</option>
              <option value="60">Over 60 Days</option>
              <option value="NEVER">Never Visited</option>
            </SearchableSelect>
          </label>
          <label className="customer-filter-field customer-ltv-field">
            <span>Lifetime Value</span>
            <SearchableSelect value={lifetimeValue} onChange={(event) => setLifetimeValue(event.target.value)}>
              <option value="ALL">All Values</option>
              <option value="0-999">Below ₱1,000</option>
              <option value="1000-4999">₱1,000 - ₱4,999</option>
              <option value="5000-9999">₱5,000 - ₱9,999</option>
              <option value="10000-19999">₱10,000 - ₱19,999</option>
              <option value="20000-49999">₱20,000 - ₱49,999</option>
              <option value="50000+">₱50,000+</option>
            </SearchableSelect>
          </label>
          <div className="customer-filter-field customer-promo-field">
            <span>Promo Code</span>
            <PromoCodeDropdown
              value={promoCode}
              options={references.promo_codes || []}
              onChange={setPromoCode}
            />
          </div>
          <button className={`secondary-button customer-audience-trigger ${audienceSummary ? 'active' : ''}`} type="button" onClick={openAudienceBuilder}>
            {audienceSummary ? 'Edit Smart Audience' : 'Smart Audience'}
          </button>
          <button className="secondary-button customer-filter-reset" type="button" onClick={resetFilters}>Reset</button>
        </div>

        {promoCode.trim() ? (
          <div className={`customer-promo-summary ${promoFilter?.found ? 'success' : 'neutral'}`}>
            {promoLoading
              ? `Checking ${promoCode.trim()}...`
              : promoFilter?.found
                ? `${promoFilter.code}: ${promoFilter.qualified_customer_count} qualified customer${promoFilter.qualified_customer_count === 1 ? '' : 's'}. Customer criteria are applied here; checkout-specific restrictions are still validated during Checkout.`
                : promoFilter?.message || 'No active promo rule found for this code.'}
          </div>
        ) : null}

        {audienceSummary ? (
          <div className="customer-audience-summary">
            <div>
              <strong>Smart Audience:</strong> {audienceSummary.matched_count} of {audienceSummary.total_customers} customers matched {audienceSummary.groups} group{audienceSummary.groups === 1 ? '' : 's'} / {audienceSummary.conditions} condition{audienceSummary.conditions === 1 ? '' : 's'}. Existing quick filters can narrow this result further.
            </div>
            <button type="button" className="text-button" onClick={clearAudience}>Clear</button>
          </div>
        ) : null}

        {loading ? <div className="empty-state">Loading customers...</div> : (
          <div className="table-wrap">
            <table className="data-table customers-table">
              <colgroup>
                <col className="customer-col-name" />
                <col className="customer-col-mobile" />
                <col className="customer-col-registration" />
                <col className="customer-col-source" />
                <col className="customer-col-status" />
                <col className="customer-col-visits" />
                <col className="customer-col-type" />
                <col className="customer-col-ltv" />
                <col className="customer-col-action" />
              </colgroup>
              <thead><tr><th>Customer</th><th>Mobile</th><th>Registration</th><th>Lead Source</th><th>Status</th><th>Visits</th><th>Type</th><th className="money-column">Lifetime Value</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {filteredCustomers.map((customer) => {
                  const actions = [
                    { label: 'View Profile', onClick: () => openCustomerProfile(customer) },
                    hasPermission('customers', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(customer) } : null,
                    hasPermission('customers', 'TOGGLE') ? { label: customer.status === 'ACTIVE' ? 'Deactivate' : 'Activate', danger: customer.status === 'ACTIVE', onClick: () => toggle(customer) } : null,
                  ]
                  const visitCount = Number(customer.total_completed_visits || 0)
                  return (
                    <tr key={customer.id}>
                      <td>
                        <button type="button" className="customer-profile-link" onClick={() => openCustomerProfile(customer)} disabled={profileLoading}>{customer.full_name}</button> <CustomerSegmentTag customerClass={customer.customer_class} customerType={customer.customer_type || customer.segment} typeColor={customer.customer_type_color} />
                        <span className="table-subline">{customer.code}</span>
                        {customer.is_test_customer ? <span className="table-subline">Test Customer</span> : null}
                      </td>
                      <td>{customer.mobile_number || '—'}</td>
                      <td>{formatDisplayDate(customer.registration_date)}</td>
                      <td>{customer.lead_source || '—'}</td>
                      <td><span className={`status-pill ${customer.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{customer.status}</span></td>
                      <td><strong>{visitCount}</strong>{customer.last_visit ? <span className="table-subline">Last: {formatDisplayDate(customer.last_visit)}</span> : null}</td>
                      <td><span className="customer-type-table-value"><span className="customer-type-table-dot" style={{ backgroundColor: customer.customer_type_color || '#8b8b8f' }} aria-hidden="true" /><strong>{customer.customer_type || customer.segment || 'Unclassified'}</strong></span></td>
                      <td className="money-cell">{peso(customer.lifetime_value)}</td>
                      <td className="action-cell"><ActionMenu items={actions} /></td>
                    </tr>
                  )
                })}
                {!filteredCustomers.length && <tr><td colSpan="9"><div className="empty-state">No customers found.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {audienceOpen && audienceOptions ? (
        <CustomerAudienceBuilder
          options={audienceOptions}
          initialDefinition={audienceDefinition}
          onClose={() => setAudienceOpen(false)}
          onApply={applyAudience}
          applying={audienceApplying}
        />
      ) : null}

      {customerProfile ? (
        <Modal title={customerProfile.customer?.full_name || 'Customer Profile'} subtitle={customerProfile.customer?.code || ''} onClose={() => setCustomerProfile(null)} wide className="customer-profile-modal">
          <div className="customer-profile-stack">
            <section className="customer-profile-summary-grid">
              <article className="summary-card"><span>Customer Type</span><strong>{customerProfile.profile?.summary?.customer_type || 'Unclassified'}</strong></article>
              <article className="summary-card"><span>Customer Class</span><strong>{customerProfile.profile?.summary?.customer_class || 'Unclassified'}</strong></article>
              <article className="summary-card"><span>Lifetime Value</span><strong>{peso(customerProfile.profile?.summary?.lifetime_value)}</strong></article>
              <article className="summary-card"><span>Outstanding Balance</span><strong>{peso(customerProfile.profile?.summary?.outstanding_balance)}</strong></article>
              <article className="summary-card"><span>Completed Visits</span><strong>{Number(customerProfile.profile?.summary?.completed_visits || 0)}</strong></article>
              <article className="summary-card"><span>Service Balances</span><strong>{Number(customerProfile.profile?.summary?.active_service_balances || 0)}</strong></article>
              <article className="summary-card"><span>Transactions</span><strong>{Number(customerProfile.profile?.summary?.transaction_count || 0)}</strong></article>
            </section>

            <section className="customer-profile-info-grid">
              <div><span>Mobile</span><strong>{customerProfile.customer?.mobile_number || '—'}</strong></div>
              <div><span>Email</span><strong>{customerProfile.customer?.email || '—'}</strong></div>
              <div><span>Last Visit</span><strong>{customerProfile.profile?.summary?.last_visit ? formatDisplayDate(customerProfile.profile.summary.last_visit) : '—'}</strong></div>
              <div><span>Status</span><strong>{customerProfile.customer?.status || '—'}</strong></div>
            </section>

            <section>
              <div className="section-heading compact-section-heading"><div><h2>Remaining Service Balances</h2></div></div>
              <div className="table-wrap"><table className="data-table customer-profile-balance-table"><thead><tr><th>Package / Service</th><th>Purchased</th><th>Used</th><th>Remaining</th><th>Status</th><th>Expiry</th></tr></thead><tbody>
                {(customerProfile.profile?.service_balances || []).map((row) => <tr key={row.id}><td><strong>{row.service_name}</strong><span className="table-subline">{row.code}</span></td><td>{row.purchased}</td><td>{row.used}</td><td><strong>{row.remaining}</strong></td><td>{row.status}</td><td>{row.expiry_date ? formatDisplayDate(row.expiry_date) : '—'}</td></tr>)}
                {!(customerProfile.profile?.service_balances || []).length ? <tr><td colSpan="6"><div className="empty-state compact-empty">No remaining service balances.</div></td></tr> : null}
              </tbody></table></div>
            </section>

            <section>
              <div className="section-heading compact-section-heading"><div><h2>Transaction History</h2><p>Services, packages, and retail purchases under this customer record.</p></div></div>
              <div className="table-wrap"><table className="data-table customer-profile-transactions-table"><thead><tr><th>Date</th><th>Transaction</th><th>Type</th><th>Items</th><th>Net</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
                {(customerProfile.profile?.transactions || []).map((row) => <tr key={row.id}><td>{row.date ? formatDisplayDate(row.date) : '—'}</td><td><strong>{row.booking_code || 'Direct Sale'}</strong><span className="table-subline">{row.code || '—'}</span><span className="table-subline">{row.branch || '—'}</span></td><td>{row.sale_type}</td><td><div className="customer-profile-item-stack">{(row.items || []).map((item) => <span key={item.id}><strong>{item.name}</strong> × {item.quantity}</span>)}</div></td><td>{peso(row.net_amount)}</td><td>{peso(row.paid_amount)}</td><td>{peso(row.outstanding_balance)}</td><td>{row.status}</td></tr>)}
                {!(customerProfile.profile?.transactions || []).length ? <tr><td colSpan="8"><div className="empty-state compact-empty">No transaction history.</div></td></tr> : null}
              </tbody></table></div>
            </section>
          </div>
        </Modal>
      ) : null}

      {form && (
        <Modal title={form.id ? 'Edit Customer' : 'Add Customer'} onClose={() => setForm(null)} wide>
          {formError ? <div className="form-alert error">{formError}</div> : null}
          <form className="form-grid" onSubmit={save}>
            <label className="form-field">First Name<input value={form.first_name} onChange={(e) => setForm((v) => ({ ...v, first_name: e.target.value }))} required /></label>
            <label className="form-field">Last Name<input value={form.last_name} onChange={(e) => setForm((v) => ({ ...v, last_name: e.target.value }))} required /></label>
            <label className="form-field">Mobile Number<input value={form.mobile_number} onChange={(e) => setForm((v) => ({ ...v, mobile_number: e.target.value }))} /></label>
            <label className="form-field">Email<input type="email" value={form.email} onChange={(e) => setForm((v) => ({ ...v, email: e.target.value }))} /></label>
            <label className="form-field">Birthday<input type="date" value={form.birthday} onChange={(e) => setForm((v) => ({ ...v, birthday: e.target.value }))} /></label>
            <label className="form-field">Gender<SearchableSelect value={form.gender_code} onChange={(e) => setForm((v) => ({ ...v, gender_code: e.target.value }))}><option value="">Select</option>{references.genders.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
            <label className="form-field">Lead Source<SearchableSelect value={form.lead_source_code} onChange={(e) => setForm((v) => ({ ...v, lead_source_code: e.target.value }))}><option value="">Select</option>{references.lead_sources.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
            <label className="form-field">Registration Date<input type="date" value={form.registration_date} onChange={(e) => setForm((v) => ({ ...v, registration_date: e.target.value }))} /></label>
            <label className="form-field form-span-2">Address<textarea rows="2" value={form.address} onChange={(e) => setForm((v) => ({ ...v, address: e.target.value }))} /></label>
            <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => setForm((v) => ({ ...v, notes: e.target.value }))} /></label>
            {!form.id && user?.role?.is_super_admin ? (
              <label className="checkbox-field form-span-2">
                <input type="checkbox" checked={Boolean(form.is_test_customer)} onChange={(e) => setForm((v) => ({ ...v, is_test_customer: e.target.checked }))} />
                <span>
                  <strong>Test Customer Only</strong>
                  <small>Marks this customer and all linked transactions as eligible for the test-only DevReset.</small>
                </span>
              </label>
            ) : null}
            <div className="field-helper form-span-2">Duplicate control applies when a mobile number is provided, or when both name and birthday match an existing customer.</div>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Customer'}</button></div>
          </form>
        </Modal>
      )}
    </div>
  )
}
