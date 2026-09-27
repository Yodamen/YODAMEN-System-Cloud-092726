import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import './leave.css'
import './leave-update79.css'

function emptyForm(requestType) {
  return {
    request_type: requestType,
    leave_type_id: '',
    start_date: '',
    end_date: '',
    duration_type: 'FULL_DAY',
    reason: '',
  }
}

function dateText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`))
}

function statusClass(status) {
  if (status === 'APPROVED') return 'success'
  if (status === 'PENDING') return 'warning'
  if (status === 'REJECTED' || status === 'CANCELLED') return 'danger'
  return 'neutral'
}

export default function MyLeavePage({ embedded = false, requestType = 'LEAVE', refreshVersion = 0 }) {
  const fixedRequestType = requestType === 'DAY_OFF' ? 'DAY_OFF' : 'LEAVE'
  const [data, setData] = useState(null)
  const [form, setForm] = useState(() => emptyForm(fixedRequestType))
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [cancelRequest, setCancelRequest] = useState(null)
  const [viewRequest, setViewRequest] = useState(null)
  const [rescheduleOpen, setRescheduleOpen] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/hr/my-leave')
    setData(response.data)
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => setForm(emptyForm(fixedRequestType)), 0)
    return () => window.clearTimeout(timer)
  }, [fixedRequestType])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setLoading(true)
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load requests.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [load, refreshVersion])

  const selectedType = useMemo(
    () => data?.leave_types?.find((row) => Number(row.id) === Number(form.leave_type_id)),
    [data, form.leave_type_id],
  )

  const visibleRequests = useMemo(
    () => (data?.requests || []).filter((row) => row.request_type === fixedRequestType),
    [data, fixedRequestType],
  )

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      const payload = fixedRequestType === 'DAY_OFF'
        ? {
            ...form,
            request_type: 'DAY_OFF',
            leave_type_id: null,
            end_date: form.start_date,
            duration_type: 'FULL_DAY',
          }
        : {
            ...form,
            request_type: 'LEAVE',
            leave_type_id: Number(form.leave_type_id),
          }

      await api.post('/v1/hr/my-leave', payload)
      setForm(emptyForm(fixedRequestType))
      setRescheduleOpen(false)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit request.'))
    } finally {
      setSaving(false)
    }
  }

  async function confirmCancel() {
    if (!cancelRequest) return
    setSaving(true)
    setMessage('')
    try {
      await api.patch(`/v1/hr/my-leave/${cancelRequest.id}/cancel`)
      setCancelRequest(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to cancel request.'))
    } finally {
      setSaving(false)
    }
  }

  const title = fixedRequestType === 'LEAVE' ? 'Leave' : 'Day Off'

  return (
    <div className="page-stack my-leave-embedded">
      {!embedded ? (
        <div className="page-heading">
          <div>
            <p className="eyebrow">HR & Payroll</p>
            <h1 className="live-page-title">My {title} <PageRefreshButton label={`Refresh ${title.toLowerCase()}`} onRefresh={load} /></h1>
          </div>
        </div>
      ) : null}

      {message ? <div className="form-alert error">{message}</div> : null}

      {fixedRequestType === 'LEAVE' ? (
        <section className="content-card self-service-balance-card">
          <div className="section-heading compact-section-heading"><div><h2>Leave Balance</h2><p>{data?.year || new Date().getFullYear()}</p></div></div>
          {loading ? <div className="empty-state compact-empty">Loading balances...</div> : (
            <div className="leave-balance-strip">
              {(data?.balances || []).map((row) => (
                <div className="leave-balance-item" key={row.leave_type_id}>
                  <span>{row.leave_type_name}</span>
                  <strong>{!row.paid_leave_entitled && row.is_paid ? 'Not entitled' : row.effective_consumes_balance ? `${Number(row.available_days).toFixed(2)} days` : 'No balance required'}</strong>
                </div>
              ))}
            </div>
          )}
        </section>
      ) : null}

      {fixedRequestType === 'DAY_OFF' ? (
        <section className="content-card day-off-schedule-card">
          <div className="day-off-schedule-heading">
            <div className="section-heading compact-section-heading"><div><h2>Working Schedule</h2><p>Schedule assigned by Admin under Personnel settings.</p></div></div>
            <button className="secondary-button compact-button" type="button" onClick={() => setRescheduleOpen(true)}>Reschedule</button>
          </div>
          {loading ? <div className="empty-state compact-empty">Loading working schedule...</div> : (
            <div className="day-off-weekly-schedule">
              {(data?.work_schedule?.weekly || []).map((row) => {
                const names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
                return (
                  <article className={`day-off-schedule-day ${row.is_working_day ? '' : 'off'}`} key={row.day_of_week}>
                    <span>{names[row.day_of_week - 1]}</span>
                    <strong>{row.is_working_day ? 'Working Day' : 'Day Off'}</strong>
                    <small>{row.is_working_day ? `${row.start_time || '—'} - ${row.end_time || '—'}` : 'No working hours'}</small>
                  </article>
                )
              })}
            </div>
          )}
        </section>
      ) : (
        <section className="content-card self-service-request-card">
          <div className="section-heading compact-section-heading"><div><h2>File Leave</h2></div></div>
          <form className="self-service-request-form" onSubmit={submit}>
            <label className="form-field">
              <span>Leave Type</span>
              <SearchableSelect required value={form.leave_type_id} onChange={(e) => setForm((current) => ({ ...current, leave_type_id: e.target.value }))}>
                <option value="">Select leave type</option>
                {(data?.leave_types || []).map((row) => <option key={row.id} value={row.id}>{row.name}{row.effective_is_paid ? ' · Paid' : row.is_paid ? ' · Unpaid for your profile' : ''}</option>)}
              </SearchableSelect>
              {selectedType?.effective_consumes_balance ? <small>Uses available paid leave balance.</small> : null}
            </label>
            <label className="form-field"><span>Duration</span><SearchableSelect value={form.duration_type} onChange={(e) => setForm((current) => ({ ...current, duration_type: e.target.value }))}><option value="FULL_DAY">Full Day</option><option value="HALF_DAY_AM">Half Day · AM</option><option value="HALF_DAY_PM">Half Day · PM</option></SearchableSelect></label>
            <label className="form-field"><span>From</span><input required type="date" value={form.start_date} onChange={(e) => setForm((current) => ({ ...current, start_date: e.target.value, end_date: current.end_date || e.target.value }))} /></label>
            <label className="form-field"><span>To</span><input required type="date" min={form.start_date || undefined} value={form.end_date} onChange={(e) => setForm((current) => ({ ...current, end_date: e.target.value }))} /></label>
            <label className="form-field self-service-reason-field"><span>Reason</span><textarea required rows="2" value={form.reason} onChange={(e) => setForm((current) => ({ ...current, reason: e.target.value }))} /></label>
            <div className="self-service-request-actions"><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Submitting...' : 'Submit Leave'}</button></div>
          </form>
        </section>
      )}

      <section className="content-card">
        <div className="section-heading compact-section-heading"><div><h2>{title} History</h2></div></div>
        {!loading && !visibleRequests.length ? <div className="empty-state compact-empty">No {title.toLowerCase()} requests yet.</div> : null}
        {visibleRequests.length ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Request</th><th>Dates</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {visibleRequests.map((row) => (
                  <tr key={row.id}>
                    <td><strong>{row.code}</strong>{row.request_type === 'LEAVE' ? <span className="table-subline">{row.leave_type?.name}</span> : null}</td>
                    <td>{dateText(row.start_date)}{row.end_date !== row.start_date ? ` → ${dateText(row.end_date)}` : ''}<span className="table-subline">{row.duration_type.replaceAll('_', ' ')}</span></td>
                    <td><span className={`status-pill ${statusClass(row.status)}`}>{row.status}</span>{row.review_notes ? <span className="table-subline">{row.review_notes}</span> : null}</td>
                    <td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => setViewRequest(row) }, ['PENDING', 'APPROVED'].includes(row.status) ? { label: 'Cancel', onClick: () => setCancelRequest(row) } : null]} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>

      {rescheduleOpen ? <Modal title="Reschedule Day Off" subtitle="Request a different day off from your assigned working schedule." onClose={() => setRescheduleOpen(false)}>
        <form className="day-off-reschedule-form" onSubmit={submit}>
          <label className="form-field"><span>Date</span><input required type="date" value={form.start_date} onChange={(e) => setForm((current) => ({ ...current, start_date: e.target.value, end_date: e.target.value, duration_type: 'FULL_DAY' }))} /></label>
          <label className="form-field"><span>Reason</span><textarea required rows="4" value={form.reason} onChange={(e) => setForm((current) => ({ ...current, reason: e.target.value }))} /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setRescheduleOpen(false)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Submitting...' : 'Submit Reschedule'}</button></div>
        </form>
      </Modal> : null}

      {viewRequest ? <Modal title={`${viewRequest.request_type === 'LEAVE' ? 'Leave' : 'Day Off'} Request`} onClose={() => setViewRequest(null)}>
        <div className="request-detail-grid">
          <div><span>Request</span><strong>{viewRequest.code}</strong></div>
          <div><span>Status</span><strong>{viewRequest.status}</strong></div>
          {viewRequest.request_type === 'LEAVE' ? <div><span>Leave Type</span><strong>{viewRequest.leave_type?.name || '—'}</strong></div> : null}
          <div><span>Duration</span><strong>{viewRequest.duration_type.replaceAll('_', ' ')}</strong></div>
          <div className="request-detail-wide"><span>Dates</span><strong>{dateText(viewRequest.start_date)}{viewRequest.end_date !== viewRequest.start_date ? ` → ${dateText(viewRequest.end_date)}` : ''}</strong></div>
          <div className="request-detail-wide"><span>Reason</span><strong>{viewRequest.reason || '—'}</strong></div>
          {viewRequest.review_notes ? <div className="request-detail-wide"><span>Review Notes</span><strong>{viewRequest.review_notes}</strong></div> : null}
        </div>
        <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setViewRequest(null)}>Close</button></div>
      </Modal> : null}

      {cancelRequest ? <ActionConfirmationModal
        title={`Cancel ${cancelRequest.request_type === 'LEAVE' ? 'Leave' : 'Day Off'} Request`}
        message="Cancel this request? The request will remain in your history with a Cancelled status."
        confirmLabel="Cancel Request"
        danger
        busy={saving}
        details={[
          { label: 'Request', value: cancelRequest.code },
          { label: 'Dates', value: `${dateText(cancelRequest.start_date)}${cancelRequest.end_date !== cancelRequest.start_date ? ` → ${dateText(cancelRequest.end_date)}` : ''}` },
          { label: 'Status', value: cancelRequest.status },
        ]}
        onCancel={() => setCancelRequest(null)}
        onConfirm={confirmCancel}
      /> : null}
    </div>
  )
}
