import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate } from '../../utils/formatters'

function optionList(references, key) {
  return references?.[key] || []
}

function initialServiceForm(references) {
  return {
    id: null,
    name: '',
    category_code: optionList(references, 'SERVICE_CATEGORY')[0]?.code || '',
    service_count_code: optionList(references, 'SERVICE_COUNT')[0]?.code || 'SINGLE_SESSION',
    number_of_sessions: 1,
    duration_minutes: 0,
    description: '',
    status_code: 'ACTIVE',
    initial_price: '',
    effective_from: new Date().toISOString().slice(0, 10),
    price_history: [],
    new_price: '',
    new_price_effective_from: new Date().toISOString().slice(0, 10),
  }
}

export default function ServicesPricingPage() {
  const { hasPermission } = useAuth()
  const canEditService = hasPermission('services-pricing', 'EDIT')
  const canChangePrice = hasPermission('services-pricing', 'CHANGE_PRICE')
  const [services, setServices] = useState([])
  const [references, setReferences] = useState({})
  const [form, setForm] = useState(null)
  const [details, setDetails] = useState(null)
  const [message, setMessage] = useState('')
  const [formMessage, setFormMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/services')
    setServices(response.data.services || [])
    setReferences(response.data.references || {})
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load services.')))
        .finally(() => setLoading(false))
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  function openCreate() {
    setMessage('')
    setFormMessage('')
    setForm(initialServiceForm(references))
  }

  async function openEdit(service) {
    setMessage('')
    setFormMessage('')

    try {
      const response = await api.get(`/v1/admin/services/${service.id}`)
      const record = response.data.service
      setForm({
        id: record.id,
        name: record.name,
        category_code: record.category?.code || '',
        service_count_code: record.service_count?.code || 'SINGLE_SESSION',
        number_of_sessions: record.number_of_sessions || 1,
        duration_minutes: record.duration_minutes || 0,
        description: record.description || '',
        status_code: record.status?.code || 'ACTIVE',
        initial_price: '',
        effective_from: '',
        price_history: record.price_history || [],
        new_price: '',
        new_price_effective_from: new Date().toISOString().slice(0, 10),
      })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load service details.'))
    }
  }

  async function saveService(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setFormMessage('')

    try {
      const payload = {
        name: form.name,
        category_code: form.category_code,
        service_count_code: form.service_count_code,
        number_of_sessions: Number(form.number_of_sessions || 1),
        duration_minutes: Number(form.duration_minutes || 0),
        description: form.description,
        status_code: form.status_code,
      }

      if (form.id) {
        await api.put(`/v1/admin/services/${form.id}`, payload)
      } else {
        await api.post('/v1/admin/services', {
          ...payload,
          initial_price: Number(form.initial_price),
          effective_from: form.effective_from,
        })
      }

      setForm(null)
      await load()
    } catch (error) {
      setFormMessage(getApiErrorMessage(error, 'Unable to save the service.'))
    } finally {
      setSaving(false)
    }
  }

  async function schedulePrice() {
    if (!form?.id || form.new_price === '' || !form.new_price_effective_from) return

    setSaving(true)
    setFormMessage('')

    try {
      await api.post(`/v1/admin/services/${form.id}/prices`, {
        price: Number(form.new_price),
        effective_from: form.new_price_effective_from,
      })

      const response = await api.get(`/v1/admin/services/${form.id}`)
      const record = response.data.service
      setForm((current) => ({
        ...current,
        price_history: record.price_history || [],
        new_price: '',
        new_price_effective_from: new Date().toISOString().slice(0, 10),
      }))
      setFormMessage('Price history updated.')
      await load()
    } catch (error) {
      setFormMessage(getApiErrorMessage(error, 'Unable to schedule the service price.'))
    } finally {
      setSaving(false)
    }
  }

  async function openDetails(service) {
    setMessage('')
    try {
      const response = await api.get(`/v1/admin/services/${service.id}`)
      setDetails(response.data.service)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load service details.'))
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading">
        <h1>Services &amp; Pricing</h1>
        {hasPermission('services-pricing', 'CREATE') && (
          <button className="primary-button" type="button" onClick={openCreate}>+ Add Service</button>
        )}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        {loading ? (
          <div className="empty-state">Loading services...</div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Service</th>
                  <th>Category</th>
                  <th>Count</th>
                  <th>Sessions</th>
                  <th>Duration</th>
                  <th className="money-cell">Current Price</th>
                  <th>Status</th>
                  <th className="actions-column">Action</th>
                </tr>
              </thead>
              <tbody>
                {services.map((service) => (
                  <tr key={service.id}>
                    <td><strong>{service.name}</strong><small className="table-subline">{service.code}</small></td>
                    <td>{service.category?.display_value || '—'}</td>
                    <td>{service.service_count?.display_value || '—'}</td>
                    <td>{service.number_of_sessions}</td>
                    <td>{service.duration_minutes ? `${service.duration_minutes} min` : '—'}</td>
                    <td className="money-cell">{service.current_price ? `₱${Number(service.current_price.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` : '—'}</td>
                    <td><span className={`status-pill ${service.status?.code === 'ACTIVE' ? 'success' : 'neutral'}`}>{service.status?.display_value || '—'}</span></td>
                    <td className="action-cell">
                      <ActionMenu items={[
                        { label: 'View', onClick: () => openDetails(service) },
                        canEditService || canChangePrice ? { label: 'Edit', onClick: () => openEdit(service) } : null,
                      ]} />
                    </td>
                  </tr>
                ))}
                {!services.length && <tr><td colSpan="8"><div className="empty-state">No services yet.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form && (
        <Modal title={form.id ? 'Edit Service' : 'Add Service'} onClose={() => setForm(null)} wide>
          {formMessage ? <div className={`form-alert ${formMessage === 'Price history updated.' ? 'success' : 'error'}`}>{formMessage}</div> : null}
          <form className="form-grid" onSubmit={(event) => { if (form.id && !canEditService) event.preventDefault(); else saveService(event) }}>
            <label className="form-field form-span-2">Service Name<input value={form.name} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required /></label>

            <label className="form-field">Service Category<select value={form.category_code} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, category_code: event.target.value }))}>{optionList(references, 'SERVICE_CATEGORY').map((option) => <option key={option.code} value={option.code}>{option.display_value}</option>)}</select></label>

            <label className="form-field">Service Count<select value={form.service_count_code} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, service_count_code: event.target.value, number_of_sessions: event.target.value === 'SINGLE_SESSION' ? 1 : current.number_of_sessions }))}>{optionList(references, 'SERVICE_COUNT').map((option) => <option key={option.code} value={option.code}>{option.display_value}</option>)}</select></label>

            <label className="form-field">Number of Sessions<input type="number" min="1" disabled={(Boolean(form.id) && !canEditService) || form.service_count_code === 'SINGLE_SESSION'} value={form.service_count_code === 'SINGLE_SESSION' ? 1 : form.number_of_sessions} onChange={(event) => setForm((current) => ({ ...current, number_of_sessions: event.target.value }))} /></label>
            <label className="form-field">Duration (Minutes)<input type="number" min="0" value={form.duration_minutes} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, duration_minutes: event.target.value }))} /></label>
            <label className="form-field">Status<select value={form.status_code} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, status_code: event.target.value }))}>{optionList(references, 'SERVICE_STATUS').map((option) => <option key={option.code} value={option.code}>{option.display_value}</option>)}</select></label>

            {!form.id && (
              <>
                <label className="form-field">Initial Selling Price<input type="number" min="0" step="0.01" value={form.initial_price} onChange={(event) => setForm((current) => ({ ...current, initial_price: event.target.value }))} required /></label>
                <label className="form-field">Price Effective From<input type="date" value={form.effective_from} onChange={(event) => setForm((current) => ({ ...current, effective_from: event.target.value }))} required /></label>
              </>
            )}

            <label className="form-field form-span-2">Description<textarea rows="3" value={form.description} disabled={Boolean(form.id) && !canEditService} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} /></label>

            {form.id && (
              <div className="form-span-2 section-box pricing-history-section">
                <div className="section-heading-row"><h3>Pricing</h3></div>
                {canChangePrice && (
                  <div className="inline-price-editor">
                    <label className="form-field">New Selling Price<input type="number" min="0" step="0.01" value={form.new_price} onChange={(event) => setForm((current) => ({ ...current, new_price: event.target.value }))} /></label>
                    <label className="form-field">Effective From<input type="date" value={form.new_price_effective_from} onChange={(event) => setForm((current) => ({ ...current, new_price_effective_from: event.target.value }))} /></label>
                    <button className="secondary-button inline-price-button" type="button" onClick={schedulePrice} disabled={saving || form.new_price === ''}>Add Price</button>
                  </div>
                )}

                <div className="table-wrap price-history-table">
                  <table className="data-table">
                    <thead><tr><th>Price ID</th><th className="money-cell">Price</th><th>Effective From</th><th>Effective Until</th><th>Status</th></tr></thead>
                    <tbody>
                      {(form.price_history || []).map((price) => (
                        <tr key={price.id}>
                          <td>{price.code}</td>
                          <td className="money-cell">₱{Number(price.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                          <td>{formatDisplayDate(price.effective_from)}</td>
                          <td>{price.effective_until ? formatDisplayDate(price.effective_until) : 'Open'}</td>
                          <td><span className={`status-pill ${price.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{price.status}</span></td>
                        </tr>
                      ))}
                      {!form.price_history?.length && <tr><td colSpan="5"><div className="empty-state compact-empty">No price history yet.</div></td></tr>}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button>
              {(!form.id || canEditService) && <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Service'}</button>}
            </div>
          </form>
        </Modal>
      )}

      {details && (
        <Modal title={details.name} onClose={() => setDetails(null)} wide>
          <div className="detail-grid">
            <div><span>Category</span><strong>{details.category?.display_value || '—'}</strong></div>
            <div><span>Service Count</span><strong>{details.service_count?.display_value || '—'}</strong></div>
            <div><span>Sessions</span><strong>{details.number_of_sessions}</strong></div>
            <div><span>Duration</span><strong>{details.duration_minutes ? `${details.duration_minutes} min` : '—'}</strong></div>
          </div>

          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Price ID</th><th className="money-cell">Price</th><th>Effective From</th><th>Effective Until</th><th>Status</th></tr></thead>
              <tbody>
                {(details.price_history || []).map((price) => (
                  <tr key={price.id}>
                    <td>{price.code}</td>
                    <td className="money-cell">₱{Number(price.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    <td>{formatDisplayDate(price.effective_from)}</td>
                    <td>{price.effective_until ? formatDisplayDate(price.effective_until) : 'Open'}</td>
                    <td><span className={`status-pill ${price.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{price.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Modal>
      )}
    </div>
  )
}
