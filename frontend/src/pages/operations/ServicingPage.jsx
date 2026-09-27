import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import ServiceList from '../../components/common/ServiceList'
import { useAuth } from '../../context/AuthContext'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import PaymentIndicator from '../../components/operations/PaymentIndicator'

const emptyAddService = { service_id: '', quantity: 1, assigned_personnel_id: '', notes: '' }
const emptyRetailItem = () => ({ id: null, retail_product_id: '', item_name: '', quantity: 1, unit_price: 0, assigned_personnel_id: '', notes: '' })

function isPackageService(service) {
  return Number(service?.number_of_sessions || 1) > 1 || service?.service_count === 'MULTI_SESSION'
}

function serviceOptionLabel(service) {
  const sessions = Number(service?.number_of_sessions || 1)
  const sessionLabel = isPackageService(service) ? ` · ${sessions} Sessions` : ''
  const priceLabel = service.current_price != null ? ` · ₱${service.current_price.toLocaleString()}` : ''
  return `${service.name}${sessionLabel}${priceLabel}`
}

function retailItemsForCompletion(items = []) {
  return items.map((item) => ({
    id: item.id,
    retail_product_id: item.retail_product_id ? String(item.retail_product_id) : '',
    item_name: item.item_name || '',
    quantity: Number(item.quantity || 1),
    unit_price: Number(item.unit_price || 0),
    assigned_personnel_id: item.assigned_personnel_id ? String(item.assigned_personnel_id) : '',
    notes: item.notes || '',
  }))
}

export default function ServicingPage() {
  const { hasPermission } = useAuth()
  const [bootstrap, setBootstrap] = useState({ services: [], personnel: [], retail_personnel: [], retail_products: [], phase_notes: {} })
  const [rows, setRows] = useState([])
  const [manageVisitId, setManageVisitId] = useState(null)
  const [cancelService, setCancelService] = useState(null)
  const [manageServiceBuilderOpen, setManageServiceBuilderOpen] = useState(false)
  const [manageAddService, setManageAddService] = useState({ ...emptyAddService })
  const [manageCreditBuilderOpen, setManageCreditBuilderOpen] = useState(false)
  const [manageCreditDraft, setManageCreditDraft] = useState({ service_credit_id: '', assigned_personnel_id: '' })
  const [manageServiceCredits, setManageServiceCredits] = useState([])
  const [manageRetailItems, setManageRetailItems] = useState([])
  const [manageRetailDirty, setManageRetailDirty] = useState(false)
  const [selectedServiceIds, setSelectedServiceIds] = useState([])
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const canEdit = hasPermission('servicing', 'EDIT')
  const canComplete = hasPermission('servicing', 'COMPLETE')

  const managedVisit = useMemo(() => rows.find((visit) => visit.id === manageVisitId) || null, [rows, manageVisitId])

  const completableServiceIds = useMemo(() => (managedVisit?.services || [])
    .filter((line) => line.can_complete && line.status !== 'COMPLETED' && line.status !== 'CANCELLED')
    .map((line) => Number(line.id)), [managedVisit])
  const allCompletableSelected = completableServiceIds.length > 0
    && completableServiceIds.every((id) => selectedServiceIds.includes(id))

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/operations/servicing/bootstrap')
    setBootstrap(response.data)
  }, [])

  const loadVisits = useCallback(async () => {
    const response = await api.get('/v1/operations/servicing', { params: { status: 'IN_SERVICE' } })
    setRows(response.data.visits || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadBootstrap(), loadVisits()])
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Servicing.')))
        .finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [loadBootstrap, loadVisits])

  async function refreshPageData() {
    setMessage('')
    try {
      await Promise.all([loadBootstrap(), loadVisits()])
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh Servicing.'))
    }
  }

  async function refresh() {
    await loadVisits()
  }

  async function loadManageCredits(visit) {
    if (!visit?.customer?.id) {
      setManageServiceCredits([])
      return
    }

    try {
      const response = await api.get(`/v1/operations/customers/${visit.customer.id}/service-credits`, {
        params: { booking_date: visit.visit_date || undefined },
      })
      setManageServiceCredits(response.data.service_credits || [])
    } catch (error) {
      setManageServiceCredits([])
      setMessage(getApiErrorMessage(error, 'Unable to load available Service Credits.'))
    }
  }

  async function openManage(visit) {
    setMessage('')
    setManageVisitId(visit.id)
    setManageServiceBuilderOpen(false)
    setManageAddService({ ...emptyAddService })
    setManageCreditBuilderOpen(false)
    setManageCreditDraft({ service_credit_id: '', assigned_personnel_id: '' })
    setManageRetailItems(retailItemsForCompletion(visit.retail_items))
    setManageRetailDirty(false)
    setSelectedServiceIds([])
    await loadManageCredits(visit)
  }

  function closeManage() {
    setManageVisitId(null)
    setManageServiceBuilderOpen(false)
    setManageAddService({ ...emptyAddService })
    setManageCreditBuilderOpen(false)
    setManageCreditDraft({ service_credit_id: '', assigned_personnel_id: '' })
    setManageServiceCredits([])
    setManageRetailItems([])
    setManageRetailDirty(false)
    setSelectedServiceIds([])
  }

  async function assignStaff(serviceLine, personnelId) {
    setSaving(true)
    setMessage('')
    try {
      await api.patch(`/v1/operations/visit-services/${serviceLine.id}/staff`, {
        assigned_personnel_id: personnelId ? Number(personnelId) : null,
      })
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update staff assignment.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveCancel(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/visit-services/${cancelService.id}/cancel`, { reason: cancelService.reason })
      setSelectedServiceIds((current) => current.filter((id) => id !== Number(cancelService.id)))
      setCancelService(null)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to cancel service.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveManageAddService(event) {
    event.preventDefault()
    if (!managedVisit) return

    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/servicing/${managedVisit.id}/services`, {
        service_id: Number(manageAddService.service_id),
        quantity: Number(manageAddService.quantity || 1),
        assigned_personnel_id: manageAddService.assigned_personnel_id ? Number(manageAddService.assigned_personnel_id) : null,
        notes: manageAddService.notes,
      })
      setManageAddService({ ...emptyAddService })
      setManageServiceBuilderOpen(false)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to add service.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveManageCreditClaim(event) {
    event.preventDefault()
    if (!managedVisit || !manageCreditDraft.service_credit_id) return

    const credit = manageServiceCredits.find((row) => String(row.id) === String(manageCreditDraft.service_credit_id))
    if (!credit) return

    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/operations/servicing/${managedVisit.id}/services`, {
        service_id: Number(credit.service_id),
        service_credit_id: Number(credit.id),
        quantity: 1,
        assigned_personnel_id: manageCreditDraft.assigned_personnel_id ? Number(manageCreditDraft.assigned_personnel_id) : null,
      })
      setManageCreditDraft({ service_credit_id: '', assigned_personnel_id: '' })
      setManageCreditBuilderOpen(false)
      await refresh()
      await loadManageCredits(managedVisit)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to claim Service Credit.'))
    } finally {
      setSaving(false)
    }
  }

  function updateManageRetail(index, key, value) {
    setManageRetailItems((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item))
    setManageRetailDirty(true)
  }

  function addManageRetail() {
    setManageRetailItems((current) => [...current, emptyRetailItem()])
    setManageRetailDirty(true)
  }

  function removeManageRetail(index) {
    setManageRetailItems((current) => current.filter((_, itemIndex) => itemIndex !== index))
    setManageRetailDirty(true)
  }

  function toggleServiceSelection(serviceLineId) {
    setSelectedServiceIds((current) => {
      const id = Number(serviceLineId)
      return current.includes(id)
        ? current.filter((selectedId) => selectedId !== id)
        : [...current, id]
    })
  }

  function toggleAllCompletableServices() {
    setSelectedServiceIds(allCompletableSelected ? [] : completableServiceIds)
  }

  async function completeSelectedServices() {
    if (!managedVisit || !selectedServiceIds.length) return

    setSaving(true)
    setMessage('')

    try {
      const response = await api.post(`/v1/operations/servicing/${managedVisit.id}/complete-services`, {
        service_line_ids: selectedServiceIds.map(Number),
        retail_items: manageRetailItems
          .filter((item) => String(item.item_name || '').trim())
          .map((item) => ({
            id: item.id ? Number(item.id) : null,
            item_name: String(item.item_name || '').trim(),
            quantity: Math.max(1, Number(item.quantity || 1)),
            unit_price: Math.max(0, Number(item.unit_price || 0)),
            assigned_personnel_id: item.assigned_personnel_id ? Number(item.assigned_personnel_id) : null,
            notes: String(item.notes || '').trim() || null,
          })),
      })

      const updatedVisit = response.data.visit
      setSelectedServiceIds([])
      setManageRetailItems(retailItemsForCompletion(updatedVisit?.retail_items || []))
      setManageRetailDirty(false)

      await refresh()

      if (!updatedVisit || String(updatedVisit.status || '').toUpperCase() !== 'IN_SERVICE') {
        closeManage()
      } else {
        await loadManageCredits(updatedVisit)
      }
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to complete selected services.'))
    } finally {
      setSaving(false)
    }
  }

  async function saveManageRetail() {
    if (!managedVisit) return

    setSaving(true)
    setMessage('')
    try {
      const response = await api.put(`/v1/operations/servicing/${managedVisit.id}/retail-items`, {
        retail_items: manageRetailItems
          .filter((item) => String(item.item_name || '').trim())
          .map((item) => ({
            id: item.id ? Number(item.id) : null,
            item_name: String(item.item_name || '').trim(),
            quantity: Math.max(1, Number(item.quantity || 1)),
            unit_price: Math.max(0, Number(item.unit_price || 0)),
            assigned_personnel_id: item.assigned_personnel_id ? Number(item.assigned_personnel_id) : null,
            notes: String(item.notes || '').trim() || null,
          })),
      })
      setManageRetailItems(retailItemsForCompletion(response.data.visit?.retail_items || []))
      setManageRetailDirty(false)
      await refresh()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save Retail Purchases.'))
    } finally {
      setSaving(false)
    }
  }

  async function completeRetailOnlyVisit() {
    if (!managedVisit || selectedServiceIds.length) return

    const retailRows = manageRetailItems.filter((item) => String(item.item_name || '').trim())
    if (!retailRows.length) return
    if (retailRows.some((item) => !item.assigned_personnel_id)) {
      setMessage('Assign staff to every Retail Purchase before completing the Visit.')
      return
    }

    setSaving(true)
    setMessage('')
    try {
      if (manageRetailDirty) {
        await api.put(`/v1/operations/servicing/${managedVisit.id}/retail-items`, {
          retail_items: retailRows.map((item) => ({
            id: item.id ? Number(item.id) : null,
            item_name: String(item.item_name || '').trim(),
            quantity: Math.max(1, Number(item.quantity || 1)),
            unit_price: Math.max(0, Number(item.unit_price || 0)),
            assigned_personnel_id: Number(item.assigned_personnel_id),
            notes: String(item.notes || '').trim() || null,
          })),
        })
      }

      await api.post(`/v1/operations/servicing/${managedVisit.id}/complete`)
      await refresh()
      closeManage()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to complete retail purchase visit.'))
    } finally {
      setSaving(false)
    }
  }

  function statusClass(value) {
    if (value === 'COMPLETED') return 'success'
    if (['CANCELLED','REVERSED'].includes(value)) return 'danger'
    if (value === 'IN_PROGRESS' || value === 'IN_SERVICE') return 'info'
    return 'neutral'
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Servicing <PageRefreshButton label="Refresh servicing" disabled={loading || saving} onRefresh={refreshPageData} /></h1>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card live-operations-card">
        {loading ? <div className="empty-state live-empty-state">Loading Servicing...</div> : (
          <div className="table-wrap">
            <table className="data-table servicing-overview-table">
              <thead><tr><th>Booking</th><th>Customer</th><th>Services</th><th>Payment</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {rows.map((visit) => (
                  <tr key={visit.id}>
                    <td><strong>{visit.booking?.code || '—'}</strong><span className="table-subline">Check-in {visit.check_in_time || '—'}</span></td>
                    <td><strong>{visit.customer?.name || '—'}</strong> <CustomerSegmentTag customerClass={visit.customer?.customer_class} customerType={visit.customer?.customer_type || visit.customer?.segment} typeColor={visit.customer?.customer_type_color} /></td>
                    <td><ServiceList services={visit.services} excludeCancelled /></td>
                    <td><PaymentIndicator advanceAmount={visit.advance_payment_total} interimAmount={visit.interim_payment_total} /></td>
                    <td><span className={`status-pill ${statusClass(visit.status)}`}>{visit.status}</span></td>
                    <td className="action-cell"><ActionMenu items={[{ label: 'Manage', onClick: () => openManage(visit) }]} /></td>
                  </tr>
                ))}
                {!rows.length ? <tr><td colSpan="6"><div className="empty-state live-empty-state">No on-going services right now.</div></td></tr> : null}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {managedVisit && (
        <Modal title={`Servicing · ${managedVisit.booking?.code || 'Booking'}`} onClose={closeManage} wide>
          <div className="servicing-manage-header">
            <div>
              <strong>{managedVisit.customer?.name}</strong>
              <span className="table-subline">{managedVisit.booking?.code} · Check-in {managedVisit.check_in_time || '—'}</span>
            </div>
            <div>
              <span className="table-subline">Booking Payment</span>
              <PaymentIndicator advanceAmount={managedVisit.advance_payment_total} interimAmount={managedVisit.interim_payment_total} />
            </div>
          </div>

          <div className="form-grid service-completion-form">
            <section className="transaction-form-card form-span-2">
              <div className="transaction-section-title">
                <div>
                  <h3>Service</h3>
                  <p>Add services being availed during the active visit.</p>
                </div>
                {managedVisit.can_add_service && canEdit ? (
                  <button
                    className="secondary-button transaction-section-button"
                    type="button"
                    disabled={saving}
                    onClick={() => {
                      setManageAddService({ ...emptyAddService })
                      setManageServiceBuilderOpen(true)
                    }}
                  >
                    Add Service
                  </button>
                ) : null}
              </div>

              {manageServiceBuilderOpen ? (
                <form className="credit-claim-builder" onSubmit={saveManageAddService}>
                  <label className="form-field">
                    <span>Service *</span>
                    <SearchableSelect
                      required
                      value={manageAddService.service_id}
                      disabled={saving}
                      onChange={(event) => {
                        const serviceId = event.target.value
                        const selected = bootstrap.services.find((service) => String(service.id) === String(serviceId))
                        setManageAddService((current) => ({
                          ...current,
                          service_id: serviceId,
                          quantity: isPackageService(selected) ? 1 : current.quantity,
                        }))
                      }}
                    >
                      <option value="">Select service</option>
                      {bootstrap.services.map((service) => <option key={service.id} value={service.id}>{serviceOptionLabel(service)}</option>)}
                    </SearchableSelect>
                  </label>
                  <label className="form-field">
                    <span>Qty</span>
                    <input
                      type="number"
                      min="1"
                      value={manageAddService.quantity}
                      disabled={saving || isPackageService(bootstrap.services.find((service) => String(service.id) === String(manageAddService.service_id)))}
                      onChange={(event) => setManageAddService((current) => ({ ...current, quantity: event.target.value }))}
                    />
                  </label>
                  <label className="form-field">
                    <span>Assigned Staff</span>
                    <SearchableSelect
                      value={manageAddService.assigned_personnel_id}
                      disabled={saving}
                      onChange={(event) => setManageAddService((current) => ({ ...current, assigned_personnel_id: event.target.value }))}
                    >
                      <option value="">Unassigned</option>
                      {bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </SearchableSelect>
                  </label>
                  <div className="credit-builder-actions">
                    <button type="button" className="secondary-button" disabled={saving} onClick={() => setManageServiceBuilderOpen(false)}>Cancel</button>
                    <button type="submit" className="primary-button" disabled={saving || !manageAddService.service_id}>Add</button>
                  </div>
                </form>
              ) : null}

              <div className="transaction-line-items servicing-lines servicing-manage-lines">
                {managedVisit.services.filter((line) => String(line.source_type || '').toUpperCase() !== 'SERVICE_CREDIT').length ? managedVisit.services
                  .filter((line) => String(line.source_type || '').toUpperCase() !== 'SERVICE_CREDIT')
                  .map((line) => (
                  <div className="servicing-line" key={line.id}>
                    <div className="servicing-line-main servicing-line-selectable">
                      <input
                        className="servicing-complete-checkbox"
                        type="checkbox"
                        checked={selectedServiceIds.includes(Number(line.id))}
                        disabled={saving || !canComplete || !line.can_complete || line.status === 'COMPLETED' || line.status === 'CANCELLED'}
                        onChange={() => toggleServiceSelection(line.id)}
                        aria-label={`Select ${line.service_name} for completion`}
                      />
                      <div>
                        <strong>{line.service_name}</strong>
                        <span className="table-subline">{line.code} · Qty {line.quantity} · {line.source_type}</span>
                        {line.cancellation_reason ? <span className="table-subline danger-text">Reason: {line.cancellation_reason}</span> : null}
                      </div>
                    </div>
                    <div className="servicing-staff-cell">
                      {line.can_edit && canEdit ? (
                        <SearchableSelect value={line.assigned_personnel_id || ''} disabled={saving} onChange={(event) => assignStaff(line, event.target.value)}>
                          <option value="">Unassigned</option>
                          {bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </SearchableSelect>
                      ) : <span>{line.assigned_personnel_name || 'Unassigned'}</span>}
                    </div>
                    <div className="servicing-time-cell">
                      <span className={`status-pill ${statusClass(line.status)}`}>{line.status}</span>
                      <span className="table-subline">Start {line.started_time || '—'}{line.completed_time ? ` · Done ${line.completed_time}` : ''}</span>
                    </div>
                    <div className="row-actions servicing-actions">
                      {line.can_cancel && canEdit && line.status !== 'CANCELLED' ? <button className="link-button danger-link" type="button" disabled={saving} onClick={() => setCancelService({ id: line.id, name: line.service_name, reason: '' })}>Cancel</button> : null}
                      {line.completion_event ? <span className="table-subline">{line.completion_event.code}</span> : null}
                    </div>
                  </div>
                )) : <div className="transaction-inline-empty">No service added.</div>}
              </div>
            </section>

            <section className="transaction-form-card service-credit-claim-card form-span-2">
              <div className="transaction-section-title">
                <div>
                  <h3>Service Credit</h3>
                  <p>{manageServiceCredits.length ? `${manageServiceCredits.length} service credit balance${manageServiceCredits.length === 1 ? '' : 's'} available.` : 'No available service credit balance for this customer.'}</p>
                </div>
                {canEdit ? (
                  <button
                    type="button"
                    className="secondary-button transaction-section-button"
                    disabled={saving || !manageServiceCredits.length}
                    onClick={() => {
                      setManageCreditDraft({ service_credit_id: '', assigned_personnel_id: '' })
                      setManageCreditBuilderOpen(true)
                    }}
                  >
                    Claim Service Credit
                  </button>
                ) : null}
              </div>

              {manageCreditBuilderOpen ? (
                <form className="credit-claim-builder" onSubmit={saveManageCreditClaim}>
                  <label className="form-field">
                    <span>Available Credit *</span>
                    <SearchableSelect
                      required
                      value={manageCreditDraft.service_credit_id}
                      disabled={saving}
                      onChange={(event) => setManageCreditDraft((current) => ({ ...current, service_credit_id: event.target.value }))}
                    >
                      <option value="">Select service credit</option>
                      {manageServiceCredits.map((credit) => (
                        <option key={credit.id} value={credit.id}>{credit.service_name} · {credit.available_sessions} available</option>
                      ))}
                    </SearchableSelect>
                  </label>
                  <label className="form-field">
                    <span>Sessions</span>
                    <input type="number" min="1" max="1" value="1" disabled />
                  </label>
                  <label className="form-field">
                    <span>Assigned Staff</span>
                    <SearchableSelect
                      value={manageCreditDraft.assigned_personnel_id}
                      disabled={saving}
                      onChange={(event) => setManageCreditDraft((current) => ({ ...current, assigned_personnel_id: event.target.value }))}
                    >
                      <option value="">Unassigned</option>
                      {bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </SearchableSelect>
                  </label>
                  <div className="credit-builder-actions">
                    <button type="button" className="secondary-button" disabled={saving} onClick={() => setManageCreditBuilderOpen(false)}>Cancel</button>
                    <button type="submit" className="primary-button" disabled={saving || !manageCreditDraft.service_credit_id}>Add</button>
                  </div>
                </form>
              ) : null}

              <div className="transaction-line-items servicing-lines servicing-manage-lines">
                {managedVisit.services.filter((line) => String(line.source_type || '').toUpperCase() === 'SERVICE_CREDIT' && line.status !== 'CANCELLED').length ? (
                  managedVisit.services
                    .filter((line) => String(line.source_type || '').toUpperCase() === 'SERVICE_CREDIT' && line.status !== 'CANCELLED')
                    .map((line) => (
                      <div className="servicing-line" key={`credit-line-${line.id}`}>
                        <div className="servicing-line-main servicing-line-selectable">
                          <input
                            className="servicing-complete-checkbox"
                            type="checkbox"
                            checked={selectedServiceIds.includes(Number(line.id))}
                            disabled={saving || !canComplete || !line.can_complete || line.status === 'COMPLETED' || line.status === 'CANCELLED'}
                            onChange={() => toggleServiceSelection(line.id)}
                            aria-label={`Select ${line.service_name} Service Credit for completion`}
                          />
                          <div>
                            <strong>{line.service_name}</strong>
                            <span className="table-subline">Service Credit · Qty {line.quantity} · {line.source_reference_code || line.code}</span>
                          </div>
                        </div>
                        <div className="servicing-staff-cell">
                          {line.can_edit && canEdit ? (
                            <SearchableSelect value={line.assigned_personnel_id || ''} disabled={saving} onChange={(event) => assignStaff(line, event.target.value)}>
                              <option value="">Unassigned</option>
                              {bootstrap.personnel.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                            </SearchableSelect>
                          ) : <span>{line.assigned_personnel_name || 'Unassigned'}</span>}
                        </div>
                        <div className="servicing-time-cell">
                          <span className={`status-pill ${statusClass(line.status)}`}>{line.status}</span>
                          <span className="table-subline">Start {line.started_time || '—'}{line.completed_time ? ` · Done ${line.completed_time}` : ''}</span>
                        </div>
                        <div className="row-actions servicing-actions">
                          {line.can_cancel && canEdit && line.status !== 'CANCELLED' ? <button className="link-button danger-link" type="button" disabled={saving} onClick={() => setCancelService({ id: line.id, name: line.service_name, reason: '' })}>Cancel</button> : null}
                          {line.completion_event ? <span className="table-subline">{line.completion_event.code}</span> : null}
                        </div>
                      </div>
                    ))
                ) : <div className="transaction-inline-empty">No service credit claimed.</div>}
              </div>
            </section>

            <section className="transaction-form-card form-span-2">
              <div className="transaction-section-title">
                <div>
                  <h3>Retail Purchases</h3>
                  <p>Add or update products included in this active visit.</p>
                </div>
                {canEdit ? <button type="button" className="secondary-button transaction-section-button" disabled={saving} onClick={addManageRetail}>+ Add Retail</button> : null}
              </div>

              <div className="transaction-line-items">
                {manageRetailItems.length ? manageRetailItems.map((item, index) => (
                  <div className="service-completion-retail-row" key={item.id ? `manage-retail-${item.id}` : `manage-new-retail-${index}`}>
                    <label className="form-field">
                      Retail Product
                      <SearchableSelect value={item.retail_product_id || ''} disabled={!canEdit || saving} onChange={(event) => {
                        const product = (bootstrap.retail_products || []).find((row) => String(row.id) === event.target.value)
                        updateManageRetail(index, 'retail_product_id', event.target.value)
                        if (product) {
                          updateManageRetail(index, 'item_name', product.name)
                          updateManageRetail(index, 'unit_price', product.selling_price || 0)
                        }
                      }} required>
                        <option value="">Select SKU / product</option>
                        {(bootstrap.retail_products || []).map((product) => <option key={product.id} value={product.id} disabled={Number(product.available || 0) <= 0}>{product.sku} · {product.name} · ₱{Number(product.selling_price || 0).toLocaleString()} · Stock {product.available}</option>)}
                      </SearchableSelect>
                    </label>
                    <label className="form-field">
                      Qty
                      <input type="number" min="1" step="1" value={item.quantity} disabled={!canEdit || saving} onChange={(event) => updateManageRetail(index, 'quantity', event.target.value)} required />
                    </label>
                    <label className="form-field">
                      Unit Price
                      <input type="number" min="0" step="0.01" value={item.unit_price} disabled={!canEdit || saving} onChange={(event) => updateManageRetail(index, 'unit_price', event.target.value)} required />
                    </label>
                    <label className="form-field">
                      Assigned Staff
                      <SearchableSelect value={item.assigned_personnel_id || ''} disabled={!canEdit || saving} onChange={(event) => updateManageRetail(index, 'assigned_personnel_id', event.target.value)} required>
                        <option value="">Select staff</option>
                        {(bootstrap.retail_personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                      </SearchableSelect>
                    </label>
                    {canEdit ? <button type="button" className="line-remove-button" disabled={saving} onClick={() => removeManageRetail(index)}>Remove</button> : null}
                  </div>
                )) : <div className="transaction-inline-empty">No retail purchase added.</div>}
              </div>

              {canEdit && manageRetailDirty ? (
                <div className="modal-actions">
                  <button type="button" className="primary-button" disabled={saving} onClick={saveManageRetail}>{saving ? 'Saving...' : 'Save Retail Purchases'}</button>
                </div>
              ) : null}
            </section>

            <div className="modal-actions form-span-2 servicing-manage-footer">
              <label className="servicing-select-all">
                <input
                  type="checkbox"
                  checked={allCompletableSelected}
                  disabled={saving || !canComplete || !completableServiceIds.length}
                  onChange={toggleAllCompletableServices}
                />
                <span>Select all active services</span>
              </label>
              <div className="servicing-selected-count">
                {selectedServiceIds.length
                  ? `${selectedServiceIds.length} service${selectedServiceIds.length === 1 ? '' : 's'} selected`
                  : 'Select the service(s) to complete.'}
              </div>
              <button className="secondary-button" type="button" disabled={saving} onClick={closeManage}>Close</button>
              {canComplete && completableServiceIds.length ? (
                <button
                  className="primary-button"
                  type="button"
                  disabled={saving || !selectedServiceIds.length}
                  onClick={completeSelectedServices}
                >
                  {saving ? 'Completing...' : 'Complete Selected Services'}
                </button>
              ) : null}
              {canComplete && !completableServiceIds.length && manageRetailItems.some((item) => String(item.item_name || '').trim()) ? (
                <button className="primary-button" type="button" disabled={saving} onClick={completeRetailOnlyVisit}>
                  {saving ? 'Completing...' : 'Complete Retail Visit'}
                </button>
              ) : null}
            </div>
          </div>
        </Modal>
      )}

      {cancelService && (
        <Modal title={`Cancel ${cancelService.name}`} subtitle="The service line remains in Visit history. Completed services cannot be cancelled." onClose={() => setCancelService(null)}>
          <form className="form-grid" onSubmit={saveCancel}>
            <label className="form-field form-span-2">Cancellation Reason<textarea required rows="4" value={cancelService.reason} onChange={(e) => setCancelService((current) => ({ ...current, reason: e.target.value }))} /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setCancelService(null)}>Keep Service</button><button className="danger-button" type="submit" disabled={saving}>Cancel Service</button></div>
          </form>
        </Modal>
      )}


    </div>
  )
}
