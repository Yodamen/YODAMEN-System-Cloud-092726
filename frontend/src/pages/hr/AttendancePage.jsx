import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import './attendance.css'

function todayValue() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function monthStartValue() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function dateText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`))
}

function dateTimeText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', {
    year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  }).format(new Date(value))
}

function statusClass(status) {
  if (['APPROVED', 'ADJUSTED'].includes(status)) return 'success'
  if (['REJECTED', 'CANCELLED', 'REVERSED'].includes(status)) return 'danger'
  if (['COMPLETED', 'PENDING'].includes(status)) return 'warning'
  return 'neutral'
}

const DEFAULT_FILTERS = {
  from: monthStartValue(),
  to: todayValue(),
  personnel_id: '',
  status: 'ALL',
}

export default function AttendancePage() {
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState([])
  const [summary, setSummary] = useState({})
  const [personnel, setPersonnel] = useState([])
  const [filters, setFilters] = useState(DEFAULT_FILTERS)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [adjusting, setAdjusting] = useState(null)
  const [saving, setSaving] = useState(false)
  const [proofModal, setProofModal] = useState(null)

  const load = useCallback(async (nextFilters = DEFAULT_FILTERS) => {
    const response = await api.get('/v1/hr/attendance', { params: nextFilters })
    setRows(response.data.rows || [])
    setSummary(response.data.summary || {})
    setPersonnel(response.data.personnel || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load(DEFAULT_FILTERS)
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load attendance.')))
        .finally(() => setLoading(false))
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  async function applyFilters(event) {
    event?.preventDefault()
    setLoading(true)
    setMessage('')
    try {
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load attendance.'))
    } finally {
      setLoading(false)
    }
  }

  async function resetFilters() {
    setFilters(DEFAULT_FILTERS)
    setLoading(true)
    setMessage('')
    try { await load(DEFAULT_FILTERS) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load attendance.')) }
    finally { setLoading(false) }
  }

  async function approve(row) {
    setMessage('')
    try {
      await api.post(`/v1/hr/attendance/${row.id}/approve`)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to approve attendance.'))
    }
  }

  function openAdjust(row) {
    setMessage('')
    setAdjusting({
      id: row.id,
      employee: row.personnel?.name || '',
      date: row.attendance_date,
      time_in: row.time_in_value || '',
      time_out: row.time_out_value || '',
      ot_hours: row.ot_hours ?? '',
      reason: '',
      original: row,
    })
  }

  async function saveAdjustment(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      await api.put(`/v1/hr/attendance/${adjusting.id}/adjust`, {
        time_in: adjusting.time_in,
        time_out: adjusting.time_out,
        ot_hours: adjusting.ot_hours === '' ? null : Number(adjusting.ot_hours),
        reason: adjusting.reason,
      })
      setAdjusting(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to adjust attendance.'))
    } finally {
      setSaving(false)
    }
  }

  async function openProof(row) {
    setMessage('')
    const modal = {
      row,
      loading: true,
      timeInUrl: null,
      timeOutUrl: null,
    }
    setProofModal(modal)

    try {
      const [timeInResponse, timeOutResponse] = await Promise.all([
        row.proofs?.time_in
          ? api.get(`/v1/hr/attendance/${row.id}/proof/TIME_IN`, { responseType: 'blob' })
          : Promise.resolve(null),
        row.proofs?.time_out
          ? api.get(`/v1/hr/attendance/${row.id}/proof/TIME_OUT`, { responseType: 'blob' })
          : Promise.resolve(null),
      ])

      setProofModal({
        row,
        loading: false,
        timeInUrl: timeInResponse ? URL.createObjectURL(timeInResponse.data) : null,
        timeOutUrl: timeOutResponse ? URL.createObjectURL(timeOutResponse.data) : null,
      })
    } catch (error) {
      setProofModal(null)
      setMessage(getApiErrorMessage(error, 'Unable to load attendance proof.'))
    }
  }

  function closeProof() {
    if (proofModal?.timeInUrl) URL.revokeObjectURL(proofModal.timeInUrl)
    if (proofModal?.timeOutUrl) URL.revokeObjectURL(proofModal.timeOutUrl)
    setProofModal(null)
  }

  return (
    <div className="page-stack attendance-page">
      <div className="page-heading">
        <div>
          <p className="eyebrow">HR & Payroll</p>
          <h1 className="live-page-title">Attendance <PageRefreshButton label="Refresh attendance" disabled={loading} onRefresh={() => applyFilters()} /></h1>
          <p>Review staff attendance, proof photos, approvals, missing time-outs, and controlled corrections.</p>
        </div>
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="metric-grid">
        <div className="metric-card"><span>Timed In Today</span><strong>{summary.timed_in_today ?? 0}</strong></div>
        <div className="metric-card"><span>Currently Working</span><strong>{summary.currently_working ?? 0}</strong></div>
        <div className="metric-card"><span>Awaiting Approval</span><strong>{summary.awaiting_approval ?? 0}</strong></div>
        <div className="metric-card"><span>Missing Time Out</span><strong>{summary.missing_time_out ?? 0}</strong></div>
      </section>

      <section className="content-card">
        <form className="attendance-filter-grid attendance-admin-filter-grid" onSubmit={applyFilters}>
          <label className="form-field">
            <span>From</span>
            <input type="date" value={filters.from} onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value }))} />
          </label>
          <label className="form-field">
            <span>To</span>
            <input type="date" value={filters.to} onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value }))} />
          </label>
          <label className="form-field">
            <span>Employee</span>
            <SearchableSelect value={filters.personnel_id} onChange={(event) => setFilters((current) => ({ ...current, personnel_id: event.target.value }))}>
              <option value="">All Employees</option>
              {personnel.map((record) => <option key={record.id} value={record.id}>{record.name} ({record.code})</option>)}
            </SearchableSelect>
          </label>
          <label className="form-field">
            <span>Status</span>
            <SearchableSelect value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}>
              <option value="ALL">All Statuses</option>
              <option value="WORKING">Working</option>
              <option value="COMPLETED">Awaiting Approval</option>
              <option value="APPROVED">Approved</option>
              <option value="ADJUSTED">Adjusted</option>
              <option value="MISSING_TIME_OUT">Missing Time Out</option>
            </SearchableSelect>
          </label>
          <div className="attendance-filter-actions">
            <button className="primary-button" type="submit" disabled={loading}>Apply</button>
            <button className="secondary-button" type="button" disabled={loading} onClick={resetFilters}>Reset</button>
          </div>
        </form>
      </section>

      <section className="content-card">
        {loading ? <div className="empty-state">Loading attendance…</div> : null}
        {!loading && rows.length === 0 ? <div className="empty-state">No attendance records found for the selected filters.</div> : null}
        {!loading && rows.length > 0 ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Worked</th><th>OT</th><th>Status</th><th className="actions-column">Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td>{dateText(row.attendance_date)}</td>
                    <td><strong>{row.personnel?.name}</strong><span className="table-subline">{row.personnel?.code}{row.personnel?.position ? ` · ${row.personnel.position}` : ''}</span></td>
                    <td className="attendance-time-cell">{dateTimeText(row.time_in_at)}</td>
                    <td className="attendance-time-cell">{dateTimeText(row.time_out_at)}</td>
                    <td>{Number(row.worked_hours || 0).toFixed(2)} hrs</td>
                    <td>{Number(row.ot_hours || 0).toFixed(2)} hrs</td>
                    <td><span className={`status-pill ${statusClass(row.workflow_status)}`}>{row.workflow_status}</span>{row.payroll_locked ? <span className="table-subline">Payroll locked</span> : null}</td>
                    <td className="action-cell"><ActionMenu items={[
                      row.proofs?.time_in || row.proofs?.time_out ? { label: 'View Proof', onClick: () => openProof(row) } : null,
                      hasPermission('attendance', 'EDIT') && row.workflow_status === 'COMPLETED' && !row.payroll_locked ? { label: 'Approve', onClick: () => approve(row) } : null,
                      hasPermission('attendance', 'EDIT') && !row.payroll_locked ? { label: 'Adjust', onClick: () => openAdjust(row) } : null,
                    ]} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>

      {adjusting ? (
        <Modal title="Adjust Attendance" subtitle={`${adjusting.employee} · ${dateText(adjusting.date)}`} onClose={() => setAdjusting(null)}>
          <form className="form-grid" onSubmit={saveAdjustment}>
            <label className="form-field"><span>Corrected Time In</span><input type="time" required value={adjusting.time_in} onChange={(event) => setAdjusting((current) => ({ ...current, time_in: event.target.value }))} /></label>
            <label className="form-field"><span>Corrected Time Out</span><input type="time" required value={adjusting.time_out} onChange={(event) => setAdjusting((current) => ({ ...current, time_out: event.target.value }))} /></label>
            <label className="form-field"><span>OT Hours</span><input type="number" min="0" max="24" step="0.25" value={adjusting.ot_hours} onChange={(event) => setAdjusting((current) => ({ ...current, ot_hours: event.target.value }))} /><small>Leave the current value or adjust only after verification.</small></label>
            <label className="form-field form-span-2"><span>Mandatory Reason</span><textarea required value={adjusting.reason} onChange={(event) => setAdjusting((current) => ({ ...current, reason: event.target.value }))} placeholder="Explain why the attendance is being corrected." /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setAdjusting(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save Adjustment'}</button></div>
          </form>
        </Modal>
      ) : null}

      {proofModal ? (
        <Modal title="Attendance Proof" subtitle={`${proofModal.row.personnel?.name} · ${dateText(proofModal.row.attendance_date)}`} onClose={closeProof} wide>
          {proofModal.loading ? <div className="empty-state">Loading private proof…</div> : (
            <div className="attendance-proof-grid">
              <div className="attendance-proof-card"><header>Time In · {dateTimeText(proofModal.row.time_in_at)}</header>{proofModal.timeInUrl ? <img src={proofModal.timeInUrl} alt="Time In proof" /> : <div className="attendance-proof-empty">No Time In proof</div>}</div>
              <div className="attendance-proof-card"><header>Time Out · {dateTimeText(proofModal.row.time_out_at)}</header>{proofModal.timeOutUrl ? <img src={proofModal.timeOutUrl} alt="Time Out proof" /> : <div className="attendance-proof-empty">No Time Out proof</div>}</div>
            </div>
          )}
        </Modal>
      ) : null}
    </div>
  )
}
