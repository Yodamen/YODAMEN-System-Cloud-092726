import SearchableSelect from '../common/SearchableSelect'
import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import { useAuth } from '../../context/AuthContext'

function localInputDate() {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const emptyCustomer = {
  first_name: '',
  last_name: '',
  mobile_number: '',
  email: '',
  birthday: '',
  gender_code: '',
  address: '',
  lead_source_code: '',
  registration_date: localInputDate(),
  notes: '',
  is_test_customer: false,
}

export default function QuickCustomerModal({ onClose, onCreated }) {
  const { user } = useAuth()
  const [form, setForm] = useState(emptyCustomer)
  const [references, setReferences] = useState({ genders: [], lead_sources: [] })
  const [loadingReferences, setLoadingReferences] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true

    api.get('/v1/operations/customers')
      .then((response) => {
        if (!active) return
        setReferences(response.data.references || { genders: [], lead_sources: [] })
      })
      .catch((requestError) => {
        if (!active) return
        setError(getApiErrorMessage(requestError, 'Unable to load customer references.'))
      })
      .finally(() => {
        if (active) setLoadingReferences(false)
      })

    return () => {
      active = false
    }
  }, [])

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setError('')

    try {
      const response = await api.post('/v1/operations/customers', {
        first_name: form.first_name,
        last_name: form.last_name,
        mobile_number: form.mobile_number || null,
        email: form.email || null,
        birthday: form.birthday || null,
        gender_code: form.gender_code || null,
        address: form.address || null,
        lead_source_code: form.lead_source_code || null,
        registration_date: form.registration_date || null,
        notes: form.notes || null,
        is_test_customer: user?.role?.is_super_admin ? Boolean(form.is_test_customer) : false,
      })
      await onCreated?.(response.data.customer)
      onClose()
    } catch (requestError) {
      setError(getApiErrorMessage(requestError, 'Unable to create customer.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Add Customer" subtitle="Uses the same customer registration form without leaving the current transaction." onClose={onClose} wide>
      {error ? <div className="form-alert error">{error}</div> : null}
      <form className="form-grid quick-customer-form" onSubmit={save}>
        <label className="form-field">First Name<input required value={form.first_name} onChange={(event) => update('first_name', event.target.value)} /></label>
        <label className="form-field">Last Name<input required value={form.last_name} onChange={(event) => update('last_name', event.target.value)} /></label>
        <label className="form-field">Mobile Number<input value={form.mobile_number} onChange={(event) => update('mobile_number', event.target.value)} /></label>
        <label className="form-field">Email<input type="email" value={form.email} onChange={(event) => update('email', event.target.value)} /></label>
        <label className="form-field">Birthday<input type="date" value={form.birthday} onChange={(event) => update('birthday', event.target.value)} /></label>
        <label className="form-field">Gender<SearchableSelect value={form.gender_code} onChange={(event) => update('gender_code', event.target.value)} disabled={loadingReferences}><option value="">Select</option>{references.genders.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field">Lead Source<SearchableSelect value={form.lead_source_code} onChange={(event) => update('lead_source_code', event.target.value)} disabled={loadingReferences}><option value="">Select</option>{references.lead_sources.map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
        <label className="form-field">Registration Date<input type="date" value={form.registration_date} onChange={(event) => update('registration_date', event.target.value)} /></label>
        <label className="form-field form-span-2">Address<textarea rows="2" value={form.address} onChange={(event) => update('address', event.target.value)} /></label>
        <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(event) => update('notes', event.target.value)} /></label>
        {user?.role?.is_super_admin ? (
          <label className="checkbox-field form-span-2">
            <input type="checkbox" checked={Boolean(form.is_test_customer)} onChange={(event) => update('is_test_customer', event.target.checked)} />
            <span>
              <strong>Test Customer Only</strong>
              <small>Marks this customer and all linked transactions as eligible for the test-only DevReset.</small>
            </span>
          </label>
        ) : null}
        <div className="field-helper form-span-2">Duplicate control applies when a mobile number is provided, or when both name and birthday match an existing customer.</div>
        <div className="modal-actions form-span-2">
          <button className="secondary-button" type="button" onClick={onClose}>Cancel</button>
          <button className="primary-button" type="submit" disabled={saving || loadingReferences}>{saving ? 'Saving...' : 'Save Customer'}</button>
        </div>
      </form>
    </Modal>
  )
}
