import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'

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

export default function CustomersPage() {
  const { hasPermission } = useAuth()
  const [customers, setCustomers] = useState([])
  const [references, setReferences] = useState({ genders: [], lead_sources: [] })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ALL')
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [formError, setFormError] = useState('')

  const load = useCallback(async () => {
    const response = await api.get('/v1/operations/customers', {
      params: { search: search || undefined, status: status === 'ALL' ? undefined : status },
    })
    setCustomers(response.data.customers || [])
    setReferences(response.data.references || { genders: [], lead_sources: [] })
  }, [search, status])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load customers.'))).finally(() => setLoading(false))
    }, 150)
    return () => window.clearTimeout(timer)
  }, [load])

  const summary = useMemo(() => ({
    total: customers.length,
    active: customers.filter((row) => row.status === 'ACTIVE').length,
    inactive: customers.filter((row) => row.status === 'INACTIVE').length,
  }), [customers])

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
      <section className="page-heading">
        <div>
          <span className="eyebrow gold">Operations</span>
          <h1>Customers</h1>
          <p>Customer masterlist and contact profile. Visit and lifetime-value metrics activate after the Visit and Payment ledgers are migrated.</p>
        </div>
        {hasPermission('customers', 'CREATE') && <button className="primary-button" type="button" onClick={openCreate}>+ Add Customer</button>}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="summary-grid compact-summary">
        <div className="summary-card"><span>Visible Customers</span><strong>{summary.total}</strong></div>
        <div className="summary-card"><span>Active</span><strong>{summary.active}</strong></div>
        <div className="summary-card"><span>Inactive</span><strong>{summary.inactive}</strong></div>
      </section>

      <section className="content-card">
        <div className="toolbar-row">
          <label className="form-field grow-field">Search<input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, mobile or customer ID" /></label>
          <label className="form-field compact-field">Status<select value={status} onChange={(e) => setStatus(e.target.value)}><option value="ALL">All</option><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></select></label>
        </div>
        {loading ? <div className="empty-state">Loading customers...</div> : (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Customer</th><th>Mobile</th><th>Registration</th><th>Lead Source</th><th>Status</th><th>Visits</th><th className="money-cell">Lifetime Value</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {customers.map((customer) => (
                  <tr key={customer.id}>
                    <td><strong>{customer.full_name}</strong><span className="table-subline">{customer.code}</span></td>
                    <td>{customer.mobile_number}</td>
                    <td>{customer.registration_date}</td>
                    <td>{customer.lead_source || '—'}</td>
                    <td><span className={`status-pill ${customer.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{customer.status}</span></td>
                    <td>{customer.metrics_pending ? 'Pending migration' : customer.total_completed_visits}</td>
                    <td className="money-cell">{customer.metrics_pending ? 'Pending migration' : `₱${Number(customer.lifetime_value || 0).toLocaleString()}`}</td>
                    <td className="row-actions">
                      {hasPermission('customers', 'EDIT') && <button className="link-button" type="button" onClick={() => openEdit(customer)}>Edit</button>}
                      {hasPermission('customers', 'TOGGLE') && <button className="link-button" type="button" onClick={() => toggle(customer)}>{customer.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}</button>}
                    </td>
                  </tr>
                ))}
                {!customers.length && <tr><td colSpan="8"><div className="empty-state">No customers found.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form && (
        <Modal title={form.id ? 'Edit Customer' : 'Add Customer'} subtitle="Duplicate control checks both mobile number and customer name + birthday." onClose={() => setForm(null)} wide>
          {formError ? <div className="form-alert error">{formError}</div> : null}
          <form className="form-grid" onSubmit={save}>
            <label className="form-field">First Name<input value={form.first_name} onChange={(e) => setForm((v) => ({ ...v, first_name: e.target.value }))} required /></label>
            <label className="form-field">Last Name<input value={form.last_name} onChange={(e) => setForm((v) => ({ ...v, last_name: e.target.value }))} required /></label>
            <label className="form-field">Mobile Number<input value={form.mobile_number} onChange={(e) => setForm((v) => ({ ...v, mobile_number: e.target.value }))} required /></label>
            <label className="form-field">Email<input type="email" value={form.email} onChange={(e) => setForm((v) => ({ ...v, email: e.target.value }))} /></label>
            <label className="form-field">Birthday<input type="date" value={form.birthday} onChange={(e) => setForm((v) => ({ ...v, birthday: e.target.value }))} required /></label>
            <label className="form-field">Gender<select value={form.gender_code} onChange={(e) => setForm((v) => ({ ...v, gender_code: e.target.value }))}><option value="">Select</option>{references.genders.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label>
            <label className="form-field">Lead Source<select value={form.lead_source_code} onChange={(e) => setForm((v) => ({ ...v, lead_source_code: e.target.value }))}><option value="">Select</option>{references.lead_sources.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</select></label>
            <label className="form-field">Registration Date<input type="date" value={form.registration_date} onChange={(e) => setForm((v) => ({ ...v, registration_date: e.target.value }))} required /></label>
            <label className="form-field form-span-2">Address<textarea rows="2" value={form.address} onChange={(e) => setForm((v) => ({ ...v, address: e.target.value }))} /></label>
            <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => setForm((v) => ({ ...v, notes: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Customer'}</button></div>
          </form>
        </Modal>
      )}
    </div>
  )
}
