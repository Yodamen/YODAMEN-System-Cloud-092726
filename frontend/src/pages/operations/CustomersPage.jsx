import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
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

export default function CustomersPage() {
  const { hasPermission } = useAuth()
  const [customers, setCustomers] = useState([])
  const [references, setReferences] = useState({ genders: [], lead_sources: [] })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ALL')
  const [birthMonth, setBirthMonth] = useState('ALL')
  const [lastVisit, setLastVisit] = useState('ALL')
  const [lifetimeValue, setLifetimeValue] = useState('ALL')
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [formError, setFormError] = useState('')

  const load = useCallback(async () => {
    const response = await api.get('/v1/operations/customers')
    setCustomers(response.data.customers || [])
    setReferences(response.data.references || { genders: [], lead_sources: [] })
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load customers.')))
        .finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [load])

  const filteredCustomers = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return customers.filter((customer) => {
      const fullName = String(customer.full_name || `${customer.first_name || ''} ${customer.last_name || ''}`).trim().toLowerCase()
      const matchesSearch = !needle
        || fullName.includes(needle)
        || String(customer.code || '').toLowerCase().includes(needle)
        || String(customer.mobile_number || '').toLowerCase().includes(needle)
      const matchesStatus = status === 'ALL' || String(customer.status || '').toUpperCase() === status
      const birthdayMonth = customer.birthday_month ?? (customer.birthday ? Number(String(customer.birthday).slice(5, 7)) : null)
      const matchesBirthMonth = birthMonth === 'ALL' || Number(birthdayMonth) === Number(birthMonth)
      return matchesSearch
        && matchesStatus
        && matchesBirthMonth
        && matchesLastVisit(customer, lastVisit)
        && matchesLifetimeValue(customer.lifetime_value, lifetimeValue)
    })
  }, [birthMonth, customers, lastVisit, lifetimeValue, search, status])

  async function refreshPageData() {
    setMessage('')
    try {
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh customers.'))
    }
  }

  function resetFilters() {
    setSearch('')
    setStatus('ALL')
    setBirthMonth('ALL')
    setLastVisit('ALL')
    setLifetimeValue('ALL')
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
      await load()
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
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to change customer status.'))
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
            <select value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="ALL">All</option>
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
            </select>
          </label>
          <label className="customer-filter-field">
            <span>Birth Month</span>
            <select value={birthMonth} onChange={(event) => setBirthMonth(event.target.value)}>
              <option value="ALL">All Months</option>
              {months.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}
            </select>
          </label>
          <label className="customer-filter-field">
            <span>Last Visit</span>
            <select value={lastVisit} onChange={(event) => setLastVisit(event.target.value)}>
              <option value="ALL">All</option>
              <option value="14">Over 14 Days</option>
              <option value="21">Over 21 Days</option>
              <option value="30">Over 30 Days</option>
              <option value="45">Over 45 Days</option>
              <option value="60">Over 60 Days</option>
              <option value="NEVER">Never Visited</option>
            </select>
          </label>
          <label className="customer-filter-field customer-ltv-field">
            <span>Lifetime Value</span>
            <select value={lifetimeValue} onChange={(event) => setLifetimeValue(event.target.value)}>
              <option value="ALL">All Values</option>
              <option value="0-999">Below ₱1,000</option>
              <option value="1000-4999">₱1,000 - ₱4,999</option>
              <option value="5000-9999">₱5,000 - ₱9,999</option>
              <option value="10000-19999">₱10,000 - ₱19,999</option>
              <option value="20000-49999">₱20,000 - ₱49,999</option>
              <option value="50000+">₱50,000+</option>
            </select>
          </label>
          <button className="secondary-button customer-filter-reset" type="button" onClick={resetFilters}>Reset</button>
        </div>

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
                <col className="customer-col-ltv" />
                <col className="customer-col-action" />
              </colgroup>
              <thead><tr><th>Customer</th><th>Mobile</th><th>Registration</th><th>Lead Source</th><th>Status</th><th>Visits</th><th className="money-column">Lifetime Value</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {filteredCustomers.map((customer) => {
                  const actions = [
                    hasPermission('customers', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(customer) } : null,
                    hasPermission('customers', 'TOGGLE') ? { label: customer.status === 'ACTIVE' ? 'Deactivate' : 'Activate', danger: customer.status === 'ACTIVE', onClick: () => toggle(customer) } : null,
                  ]
                  const visitCount = Number(customer.total_completed_visits || 0)
                  return (
                    <tr key={customer.id}>
                      <td><strong>{customer.full_name}</strong><span className="table-subline">{customer.code}</span></td>
                      <td>{customer.mobile_number || '—'}</td>
                      <td>{formatDisplayDate(customer.registration_date)}</td>
                      <td>{customer.lead_source || '—'}</td>
                      <td><span className={`status-pill ${customer.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{customer.status}</span></td>
                      <td><strong>{visitCount}</strong>{customer.last_visit ? <span className="table-subline">Last: {formatDisplayDate(customer.last_visit)}</span> : null}</td>
                      <td className="money-cell">{peso(customer.lifetime_value)}</td>
                      <td className="action-cell"><ActionMenu items={actions} /></td>
                    </tr>
                  )
                })}
                {!filteredCustomers.length && <tr><td colSpan="8"><div className="empty-state">No customers found.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form && (
        <Modal title={form.id ? 'Edit Customer' : 'Add Customer'} onClose={() => setForm(null)} wide>
          {formError ? <div className="form-alert error">{formError}</div> : null}
          <form className="form-grid" onSubmit={save}>
            <label className="form-field">First Name<input value={form.first_name} onChange={(e) => setForm((v) => ({ ...v, first_name: e.target.value }))} required /></label>
            <label className="form-field">Last Name<input value={form.last_name} onChange={(e) => setForm((v) => ({ ...v, last_name: e.target.value }))} required /></label>
            <label className="form-field">Mobile Number<input value={form.mobile_number} onChange={(e) => setForm((v) => ({ ...v, mobile_number: e.target.value }))} /></label>
            <label className="form-field">Email<input type="email" value={form.email} onChange={(e) => setForm((v) => ({ ...v, email: e.target.value }))} /></label>
            <label className="form-field">Birthday<input type="date" value={form.birthday} onChange={(e) => setForm((v) => ({ ...v, birthday: e.target.value }))} /></label>
            <label className="form-field">Gender<select value={form.gender_code} onChange={(e) => setForm((v) => ({ ...v, gender_code: e.target.value }))}><option value="">Select</option>{references.genders.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label>
            <label className="form-field">Lead Source<select value={form.lead_source_code} onChange={(e) => setForm((v) => ({ ...v, lead_source_code: e.target.value }))}><option value="">Select</option>{references.lead_sources.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label>
            <label className="form-field">Registration Date<input type="date" value={form.registration_date} onChange={(e) => setForm((v) => ({ ...v, registration_date: e.target.value }))} /></label>
            <label className="form-field form-span-2">Address<textarea rows="2" value={form.address} onChange={(e) => setForm((v) => ({ ...v, address: e.target.value }))} /></label>
            <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => setForm((v) => ({ ...v, notes: e.target.value }))} /></label>
            <div className="field-helper form-span-2">Duplicate control applies when a mobile number is provided, or when both name and birthday match an existing customer.</div>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Customer'}</button></div>
          </form>
        </Modal>
      )}
    </div>
  )
}
