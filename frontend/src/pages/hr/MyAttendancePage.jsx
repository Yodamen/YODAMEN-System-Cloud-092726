import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import MyLeavePage from './MyLeavePage'
import './attendance.css'
import './leave.css'

function todayValue() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function thirtyDaysAgoValue() {
  const date = new Date()
  date.setDate(date.getDate() - 30)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function currentMonthValue() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function dateText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`))
}

function dateTimeText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

function serverDateTimeText(value) {
  if (!value) return '—'
  const date = new Date(value)
  const datePart = new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(date)
  const weekday = new Intl.DateTimeFormat('en-PH', { weekday: 'long' }).format(date)
  const time = new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(date)
  return `${datePart} (${weekday}), ${time}.`
}

function timeOnlyText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

function statusClass(status) {
  if (['APPROVED', 'ADJUSTED'].includes(status)) return 'success'
  if (['REJECTED', 'CANCELLED', 'REVERSED'].includes(status)) return 'danger'
  if (['COMPLETED', 'PENDING'].includes(status)) return 'warning'
  return 'neutral'
}

function changeMonth(month, delta) {
  const [year, monthNumber] = month.split('-').map(Number)
  const next = new Date(year, monthNumber - 1 + delta, 1)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}

const DEFAULT_FILTERS = { from: thirtyDaysAgoValue(), to: todayValue() }
const VALID_TABS = ['attendance', 'leave', 'day-off']

export default function MyAttendancePage() {
  const { hasPermission, user } = useAuth()
  const canUseDayOff = hasPermission('my-attendance', 'VIEW')
  const canUseLeave = canUseDayOff && user?.personnel?.paid_leave_entitled === true
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedTab = searchParams.get('tab') || 'attendance'
  const activeTab = VALID_TABS.includes(requestedTab)
    && (requestedTab === 'attendance' || (requestedTab === 'leave' && canUseLeave) || (requestedTab === 'day-off' && canUseDayOff))
    ? requestedTab
    : 'attendance'

  const [data, setData] = useState(null)
  const [filters, setFilters] = useState(DEFAULT_FILTERS)
  const [view, setView] = useState('LIST')
  const [calendarMonth, setCalendarMonth] = useState(currentMonthValue())
  const [calendar, setCalendar] = useState(null)
  const [selectedDay, setSelectedDay] = useState(null)
  const [loading, setLoading] = useState(true)
  const [calendarLoading, setCalendarLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState('')
  const [proof, setProof] = useState(null)
  const [fileKey, setFileKey] = useState(0)
  const [leaveRefreshVersion, setLeaveRefreshVersion] = useState(0)

  const load = useCallback(async (nextFilters = DEFAULT_FILTERS) => {
    const response = await api.get('/v1/hr/my-attendance', { params: nextFilters })
    setData(response.data)
  }, [])

  const loadCalendar = useCallback(async (month) => {
    setCalendarLoading(true)
    try {
      const response = await api.get('/v1/hr/my-attendance/calendar', { params: { month } })
      setCalendar(response.data)
    } finally {
      setCalendarLoading(false)
    }
  }, [])

  useEffect(() => {
    if (requestedTab !== activeTab) setSearchParams(activeTab === 'attendance' ? {} : { tab: activeTab }, { replace: true })
  }, [activeTab, requestedTab, setSearchParams])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load(DEFAULT_FILTERS)
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load My Attendance.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [load])

  useEffect(() => {
    if (activeTab !== 'attendance' || view !== 'CALENDAR') return undefined
    const timer = window.setTimeout(() => {
      loadCalendar(calendarMonth).catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load attendance calendar.')))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [activeTab, calendarMonth, loadCalendar, view])

  const action = useMemo(() => {
    if (data?.open_attendance) return 'TIME_OUT'
    if (data?.today) return null
    return 'TIME_IN'
  }, [data])

  const activeRecord = data?.open_attendance || data?.today
  const firstWeekday = calendar?.days?.length ? new Date(`${calendar.days[0].date}T00:00:00`).getDay() : 0

  function setTab(tab) {
    setMessage('')
    setSearchParams(tab === 'attendance' ? {} : { tab })
  }

  async function refreshCurrentTab() {
    if (activeTab === 'attendance') {
      if (view === 'CALENDAR') return loadCalendar(calendarMonth)
      return load(filters)
    }
    setLeaveRefreshVersion((current) => current + 1)
    return Promise.resolve()
  }

  async function submitAttendance() {
    if (!action) return
    if (!proof) { setMessage('Take or upload a proof photo first.'); return }
    setSubmitting(true)
    setMessage('')
    try {
      const body = new FormData()
      body.append('proof', proof)
      await api.post(action === 'TIME_IN' ? '/v1/hr/my-attendance/time-in' : '/v1/hr/my-attendance/time-out', body)
      setProof(null)
      setFileKey((current) => current + 1)
      await load(filters)
      if (view === 'CALENDAR') await loadCalendar(calendarMonth)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save attendance.'))
    } finally {
      setSubmitting(false)
    }
  }

  async function applyAttendanceFilters(event) {
    event.preventDefault()
    setMessage('')
    if (view === 'CALENDAR') {
      try { await loadCalendar(calendarMonth) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load attendance calendar.')) }
      return
    }

    setLoading(true)
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load My Attendance.')) } finally { setLoading(false) }
  }

  async function resetAttendanceFilters() {
    setMessage('')
    if (view === 'CALENDAR') {
      const month = currentMonthValue()
      setCalendarMonth(month)
      try { await loadCalendar(month) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load attendance calendar.')) }
      return
    }
    setFilters(DEFAULT_FILTERS)
    setLoading(true)
    try { await load(DEFAULT_FILTERS) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load My Attendance.')) } finally { setLoading(false) }
  }

  const currentStatusLabel = action === 'TIME_IN'
    ? 'READY TO TIME IN'
    : action === 'TIME_OUT'
      ? 'CURRENTLY WORKING'
      : activeRecord?.workflow_status || 'ATTENDANCE RECORDED'

  return (
    <div className="page-stack attendance-page">
      <div className="page-heading">
        <div>
          <p className="eyebrow">HR & Payroll</p>
          <h1 className="live-page-title">My Attendance <PageRefreshButton label={`Refresh ${activeTab.replace('-', ' ')}`} disabled={loading || calendarLoading} onRefresh={refreshCurrentTab} /></h1>
          <p>Attendance, leave, and day-off self-service in one place.</p>
        </div>
      </div>

      <div className="booking-tabs my-attendance-tabs" role="tablist" aria-label="My Attendance sections">
        <button type="button" className={activeTab === 'attendance' ? 'active' : ''} onClick={() => setTab('attendance')}>Attendance</button>
        {canUseLeave ? <button type="button" className={activeTab === 'leave' ? 'active' : ''} onClick={() => setTab('leave')}>Leave</button> : null}
        {canUseDayOff ? <button type="button" className={activeTab === 'day-off' ? 'active' : ''} onClick={() => setTab('day-off')}>Day Off</button> : null}
      </div>

      {activeTab === 'attendance' ? (
        <>
          {message ? <div className="form-alert error">{message}</div> : null}

          {!loading && data ? (
            <section className="content-card my-attendance-status-card">
              <div className="my-attendance-status-topline">
                <span className={`status-pill ${activeRecord ? statusClass(activeRecord.workflow_status) : 'neutral'}`}>{currentStatusLabel}</span>
                <strong className="my-attendance-current-time">{serverDateTimeText(data.server_time)}</strong>
              </div>

              <div className="my-attendance-session-row">
                <div className="my-attendance-today-details">
                  <div><span>Time In</span><strong>{timeOnlyText(activeRecord?.time_in_at)}</strong></div>
                  <div><span>Time Out</span><strong>{timeOnlyText(activeRecord?.time_out_at)}</strong></div>
                  <div><span>Worked</span><strong>{activeRecord ? `${Number(activeRecord.worked_hours || 0).toFixed(2)} hrs` : '—'}</strong></div>
                </div>

                {action ? (
                  <div className="my-attendance-proof-action">
                    <label className="attendance-proof-picker">
                      <span>Proof Photo</span>
                      <input key={fileKey} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" onChange={(event) => setProof(event.target.files?.[0] || null)} />
                      <small>{proof ? proof.name : 'JPG, PNG, or WebP · max 2 MB'}</small>
                    </label>
                    <button className="primary-button" type="button" onClick={submitAttendance} disabled={submitting}>{submitting ? 'Saving…' : action === 'TIME_IN' ? 'Time In Now' : 'Time Out Now'}</button>
                  </div>
                ) : null}
              </div>
            </section>
          ) : null}

          <section className="content-card">
            <form className="my-attendance-filterbar" onSubmit={applyAttendanceFilters}>
              <div className="my-attendance-filter-fields">
                {view === 'LIST' ? (
                  <>
                    <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value }))} /></label>
                    <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value }))} /></label>
                  </>
                ) : (
                  <label className="form-field"><span>Month</span><input type="month" value={calendarMonth} onChange={(event) => setCalendarMonth(event.target.value)} /></label>
                )}
                <button className="primary-button my-attendance-apply" type="submit" disabled={loading || calendarLoading}>Apply</button>
                <button className="secondary-button my-attendance-reset" type="button" disabled={loading || calendarLoading} onClick={resetAttendanceFilters}>Reset</button>
              </div>
              <div className="segmented-control my-attendance-view-toggle"><button type="button" className={view === 'LIST' ? 'active' : ''} onClick={() => setView('LIST')}>List</button><button type="button" className={view === 'CALENDAR' ? 'active' : ''} onClick={() => setView('CALENDAR')}>Calendar</button></div>
            </form>
          </section>

          {view === 'LIST' ? (
            <section className="content-card">
              {loading ? <div className="empty-state">Loading attendance…</div> : null}
              {!loading && (data?.rows || []).length === 0 ? <div className="empty-state">No attendance history found.</div> : null}
              {!loading && (data?.rows || []).length > 0 ? (
                <div className="table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Worked</th><th>OT</th><th>Status</th></tr></thead><tbody>{data.rows.map((row) => <tr key={row.id}><td>{dateText(row.attendance_date)}</td><td>{dateTimeText(row.time_in_at)}</td><td>{dateTimeText(row.time_out_at)}</td><td>{Number(row.worked_hours || 0).toFixed(2)} hrs</td><td>{Number(row.ot_hours || 0).toFixed(2)} hrs</td><td><span className={`status-pill ${statusClass(row.workflow_status)}`}>{row.workflow_status}</span>{row.adjustment_reason ? <span className="table-subline">Adjusted: {row.adjustment_reason}</span> : null}</td></tr>)}</tbody></table></div>
              ) : null}
            </section>
          ) : (
            <section className="content-card attendance-calendar-card">
              <div className="calendar-toolbar"><button className="secondary-button" type="button" onClick={() => setCalendarMonth((current) => changeMonth(current, -1))}>‹</button><strong>{calendar?.month_label || calendarMonth}</strong><button className="secondary-button" type="button" onClick={() => setCalendarMonth((current) => changeMonth(current, 1))}>›</button></div>
              <div className="calendar-legend"><span><i className="legend-dot present" /> Present</span><span><i className="legend-dot absent" /> Absent</span><span><i className="legend-dot day-off" /> Day Off</span><span><i className="legend-dot partial" /> Not Full Day</span><span><i className="legend-dot leave" /> Approved Leave</span></div>
              {!calendarLoading && calendar && !calendar.schedule_configured ? <div className="form-alert attendance-info">Your work schedule is not fully configured yet. Ask an administrator to set it under Personnel → Work Schedule so Absent and Day Off can be calculated accurately.</div> : null}
              {calendarLoading ? <div className="empty-state">Loading calendar...</div> : calendar ? <><div className="attendance-calendar-weekdays">{['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => <span key={day}>{day}</span>)}</div><div className="attendance-calendar-grid">{Array.from({ length: firstWeekday }).map((_, index) => <div key={`blank-${index}`} className="attendance-calendar-day blank" />)}{calendar.days.map((day) => <button type="button" key={day.date} className={`attendance-calendar-day ${day.calendar_status.toLowerCase().replaceAll('_','-')}`} onClick={() => setSelectedDay(day)}><span className="calendar-date-number">{day.day}</span><small>{day.calendar_status.replaceAll('_',' ')}</small>{day.pending_request ? <span className="calendar-request-note">Pending request</span> : null}</button>)}</div></> : null}
              {selectedDay ? <div className="calendar-day-detail"><div><strong>{dateText(selectedDay.date)}</strong><span>{selectedDay.calendar_status.replaceAll('_',' ')}</span></div><div><span>Expected</span><strong>{selectedDay.schedule.expected_hours == null ? 'Not configured' : `${Number(selectedDay.schedule.expected_hours).toFixed(2)} hrs`}</strong></div><div><span>Worked</span><strong>{selectedDay.attendance ? `${Number(selectedDay.attendance.worked_hours || 0).toFixed(2)} hrs` : '—'}</strong></div><div><span>Time</span><strong>{selectedDay.attendance ? `${dateTimeText(selectedDay.attendance.time_in_at)} → ${dateTimeText(selectedDay.attendance.time_out_at)}` : '—'}</strong></div>{selectedDay.leave ? <div><span>Leave</span><strong>{selectedDay.leave.leave_type || 'Approved Leave'} · {selectedDay.leave.duration_type.replaceAll('_',' ')}</strong></div> : null}</div> : null}
            </section>
          )}
        </>
      ) : null}

      {activeTab === 'leave' && canUseLeave ? <MyLeavePage embedded requestType="LEAVE" refreshVersion={leaveRefreshVersion} /> : null}
      {activeTab === 'day-off' && canUseDayOff ? <MyLeavePage embedded requestType="DAY_OFF" refreshVersion={leaveRefreshVersion} /> : null}
    </div>
  )
}
