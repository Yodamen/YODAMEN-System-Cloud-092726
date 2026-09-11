import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'

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
  }
}

export default function ServicesPricingPage() {
  const { hasPermission } = useAuth()
  const [services, setServices] = useState([])
  const [references, setReferences] = useState({})
  const [form, setForm] = useState(null)
  const [priceForm, setPriceForm] = useState(null)
  const [details, setDetails] = useState(null)
  const [message, setMessage] = useState('')
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
    setForm(initialServiceForm(references))
  }

  function openEdit(service) {
    setMessage('')
    setForm({
      id: service.id,
      name: service.name,
      category_code: service.category?.code || '',
      service_count_code: service.service_count?.code || 'SINGLE_SESSION',
      number_of_sessions: service.number_of_sessions || 1,
      duration_minutes: service.duration_minutes || 0,
      description: service.description || '',
      status_code: service.status?.code || 'ACTIVE',
      initial_price: '',
      effective_from: '',
    })
  }

  async function saveService(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      const payload = {
        ...form,
        number_of_sessions: Number(form.number_of_sessions || 1),
        duration_minutes: Number(form.duration_minutes || 0),
      }

      if (form.id) {
        delete payload.initial_price
        delete payload.effective_from
        await api.put(`/v1/admin/services/${form.id}`, payload)
      } else {
        payload.initial_price = Number(form.initial_price)
        await api.post('/v1/admin/services', payload)
      }

      setForm(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save the service.'))
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

  function openPrice(service) {
    setPriceForm({
      service_id: service.id,
      service_name: service.name,
      price: '',
      effective_from: new Date().toISOString().slice(0, 10),
    })
  }

  async function savePrice(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      await api.post(`/v1/admin/services/${priceForm.service_id}/prices`, {
        price: Number(priceForm.price),
        effective_from: priceForm.effective_from,
      })

      setPriceForm(null)
      setDetails(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to schedule the service price.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading">
        <div>
          <span className="eyebrow gold">Administration</span>
          <h1>Services &amp; Pricing</h1>
          <p>Maintain permanent service identities and effective-dated selling prices without overwriting price history.</p>
        </div>
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
                    <td>
                      <strong>{service.name}</strong>
                      <small className="table-subline">{service.code}</small>
                    </td>
                    <td>{service.category?.display_value || '—'}</td>
                    <td>{service.service_count?.display_value || '—'}</td>
                    <td>{service.number_of_sessions}</td>
                    <td>{service.duration_minutes ? `${service.duration_minutes} min` : '—'}</td>
                    <td className="money-cell">
                      {service.current_price ? `₱${Number(service.current_price.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` : '—'}
                    </td>
                    <td>
                      <span className={`status-pill ${service.status?.code === 'ACTIVE' ? 'success' : 'neutral'}`}>
                        {service.status?.display_value || '—'}
                      </span>
                    </td>
                    <td className="row-actions">
                      <button className="link-button" type="button" onClick={() => openDetails(service)}>View</button>
                      {hasPermission('services-pricing', 'EDIT') && (
                        <button className="link-button" type="button" onClick={() => openEdit(service)}>Edit</button>
                      )}
                      {hasPermission('services-pricing', 'CHANGE_PRICE') && (
                        <button className="link-button" type="button" onClick={() => openPrice(service)}>Price</button>
                      )}
                    </td>
                  </tr>
                ))}
                {!services.length && (
                  <tr><td colSpan="8"><div className="empty-state">No services yet.</div></td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form && (
        <Modal
          title={form.id ? 'Edit Service' : 'Add Service'}
          subtitle={form.id ? 'Service details can change. Price history is maintained separately.' : 'Create the service and its first effective price.'}
          onClose={() => setForm(null)}
          wide
        >
          <form className="form-grid" onSubmit={saveService}>
            <label className="form-field form-span-2">
              Service Name
              <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
            </label>

            <label className="form-field">
              Service Category
              <select value={form.category_code} onChange={(event) => setForm((current) => ({ ...current, category_code: event.target.value }))}>
                {optionList(references, 'SERVICE_CATEGORY').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </select>
            </label>

            <label className="form-field">
              Service Count
              <select
                value={form.service_count_code}
                onChange={(event) => setForm((current) => ({
                  ...current,
                  service_count_code: event.target.value,
                  number_of_sessions: event.target.value === 'SINGLE_SESSION' ? 1 : current.number_of_sessions,
                }))}
              >
                {optionList(references, 'SERVICE_COUNT').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </select>
            </label>

            <label className="form-field">
              Number of Sessions
              <input
                type="number"
                min="1"
                disabled={form.service_count_code === 'SINGLE_SESSION'}
                value={form.service_count_code === 'SINGLE_SESSION' ? 1 : form.number_of_sessions}
                onChange={(event) => setForm((current) => ({ ...current, number_of_sessions: event.target.value }))}
              />
            </label>

            <label className="form-field">
              Duration (Minutes)
              <input type="number" min="0" value={form.duration_minutes} onChange={(event) => setForm((current) => ({ ...current, duration_minutes: event.target.value }))} />
            </label>

            <label className="form-field">
              Status
              <select value={form.status_code} onChange={(event) => setForm((current) => ({ ...current, status_code: event.target.value }))}>
                {optionList(references, 'SERVICE_STATUS').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </select>
            </label>

            {!form.id && (
              <>
                <label className="form-field">
                  Initial Selling Price
                  <input type="number" min="0" step="0.01" value={form.initial_price} onChange={(event) => setForm((current) => ({ ...current, initial_price: event.target.value }))} required />
                </label>
                <label className="form-field">
                  Price Effective From
                  <input type="date" value={form.effective_from} onChange={(event) => setForm((current) => ({ ...current, effective_from: event.target.value }))} required />
                </label>
              </>
            )}

            <label className="form-field form-span-2">
              Description
              <textarea rows="3" value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} />
            </label>

            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Service'}</button>
            </div>
          </form>
        </Modal>
      )}

      {priceForm && (
        <Modal title="Schedule Service Price" subtitle={priceForm.service_name} onClose={() => setPriceForm(null)}>
          <form className="form-grid" onSubmit={savePrice}>
            <label className="form-field">
              Selling Price
              <input type="number" min="0" step="0.01" value={priceForm.price} onChange={(event) => setPriceForm((current) => ({ ...current, price: event.target.value }))} required />
            </label>
            <label className="form-field">
              Effective From
              <input type="date" value={priceForm.effective_from} onChange={(event) => setPriceForm((current) => ({ ...current, effective_from: event.target.value }))} required />
            </label>
            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setPriceForm(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Schedule Price'}</button>
            </div>
          </form>
        </Modal>
      )}

      {details && (
        <Modal title={details.name} subtitle={`${details.code} · Price history`} onClose={() => setDetails(null)} wide>
          <div className="detail-grid">
            <div><span>Category</span><strong>{details.category?.display_value || '—'}</strong></div>
            <div><span>Service Count</span><strong>{details.service_count?.display_value || '—'}</strong></div>
            <div><span>Sessions</span><strong>{details.number_of_sessions}</strong></div>
            <div><span>Duration</span><strong>{details.duration_minutes ? `${details.duration_minutes} min` : '—'}</strong></div>
          </div>

          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Price ID</th>
                  <th className="money-cell">Price</th>
                  <th>Effective From</th>
                  <th>Effective Until</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {(details.price_history || []).map((price) => (
                  <tr key={price.id}>
                    <td>{price.code}</td>
                    <td className="money-cell">₱{Number(price.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    <td>{price.effective_from}</td>
                    <td>{price.effective_until || 'Open'}</td>
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
