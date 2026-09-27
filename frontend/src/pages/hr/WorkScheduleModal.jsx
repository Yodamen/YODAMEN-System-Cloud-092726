import SearchableSelect from '../../components/common/SearchableSelect'
import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'

const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function defaultWeekly() {
  return DAY_NAMES.map((_, index) => ({
    day_of_week: index + 1,
    is_working_day: false,
    expected_minutes: 480,
    start_time: '',
    end_time: '',
  }))
}

export default function WorkScheduleModal({ personnel, onClose }) {
  const [weekly, setWeekly] = useState(defaultWeekly())
  const [overrides, setOverrides] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const manualOverrides = overrides.filter((row) => !row.locked)
  const generatedOverrides = overrides.filter((row) => row.locked)

  useEffect(() => {
    let active = true
    api.get(`/v1/hr/personnel/${personnel.id}/work-schedule`)
      .then((response) => {
        if (!active) return
        setWeekly((response.data.weekly || defaultWeekly()).map((row) => ({ ...row, start_time: row.start_time || '', end_time: row.end_time || '' })))
        setOverrides((response.data.overrides || []).map((row) => ({ ...row, start_time: row.start_time || '', end_time: row.end_time || '' })))
      })
      .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load work schedule.')))
      .finally(() => active && setLoading(false))
    return () => { active = false }
  }, [personnel.id])

  function updateWeekly(index, key, value) {
    setWeekly((current) => current.map((row, i) => i === index ? { ...row, [key]: value } : row))
  }

  function applyTemplate() {
    setWeekly(DAY_NAMES.map((_, index) => ({
      day_of_week: index + 1,
      is_working_day: index < 6,
      expected_minutes: 480,
      start_time: '',
      end_time: '',
    })))
  }

  function addOverride() {
    setOverrides((current) => [...current, {
      schedule_date: '', schedule_type: 'DAY_OFF', expected_minutes: 0, start_time: '', end_time: '', notes: '',
    }])
  }

  function updateOverride(index, key, value) {
    setOverrides((current) => current.map((row, i) => i === index ? { ...row, [key]: value } : row))
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.put(`/v1/hr/personnel/${personnel.id}/work-schedule`, {
        weekly: weekly.map((row) => ({
          ...row,
          expected_minutes: row.is_working_day ? Number(row.expected_minutes || 0) : 0,
          start_time: row.is_working_day && row.start_time ? row.start_time : null,
          end_time: row.is_working_day && row.end_time ? row.end_time : null,
        })),
        overrides: manualOverrides.filter((row) => row.schedule_date).map((row) => ({
          ...row,
          expected_minutes: row.schedule_type === 'WORKDAY' ? Number(row.expected_minutes || 0) : 0,
          start_time: row.schedule_type === 'WORKDAY' && row.start_time ? row.start_time : null,
          end_time: row.schedule_type === 'WORKDAY' && row.end_time ? row.end_time : null,
          notes: row.notes || null,
        })),
      })
      onClose()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save work schedule.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={`Work Schedule · ${personnel.full_name}`} subtitle="Regular weekly schedule plus approved date-specific exceptions." onClose={onClose} wide>
      {message ? <div className="form-alert error">{message}</div> : null}
      {loading ? <div className="empty-state">Loading schedule...</div> : (
        <form className="page-stack streamlined-work-schedule" onSubmit={save}>
          <section className="schedule-section">
            <div className="schedule-section-heading">
              <div><strong>Weekly Schedule</strong><span>Set the normal working days and expected hours.</span></div>
              <button className="secondary-button" type="button" onClick={applyTemplate}>Mon–Sat · 8h</button>
            </div>

            <div className="schedule-week-table">
              <div className="schedule-week-header"><span>Day</span><span>Workday</span><span>Expected</span><span>Shift</span></div>
              {weekly.map((row, index) => (
                <div className={`schedule-week-row ${row.is_working_day ? '' : 'is-day-off'}`} key={row.day_of_week}>
                  <strong>{DAY_NAMES[index]}</strong>
                  <label className="schedule-workday-toggle"><input type="checkbox" checked={row.is_working_day} onChange={(e) => updateWeekly(index, 'is_working_day', e.target.checked)} /><span>{row.is_working_day ? 'Workday' : 'Day Off'}</span></label>
                  <label className="schedule-hours-field"><input aria-label={`${DAY_NAMES[index]} expected hours`} type="number" min="0" max="24" step="0.25" disabled={!row.is_working_day} value={(Number(row.expected_minutes || 0) / 60).toString()} onChange={(e) => updateWeekly(index, 'expected_minutes', Math.round(Number(e.target.value || 0) * 60))} /><span>hrs</span></label>
                  <div className="schedule-shift-fields"><input aria-label={`${DAY_NAMES[index]} start time`} type="time" disabled={!row.is_working_day} value={row.start_time || ''} onChange={(e) => updateWeekly(index, 'start_time', e.target.value)} /><span>to</span><input aria-label={`${DAY_NAMES[index]} end time`} type="time" disabled={!row.is_working_day} value={row.end_time || ''} onChange={(e) => updateWeekly(index, 'end_time', e.target.value)} /></div>
                </div>
              ))}
            </div>
          </section>

          <section className="schedule-section">
            <div className="schedule-section-heading">
              <div><strong>Admin Schedule Exceptions</strong><span>Use only for one-off schedule changes. Employee-filed Day Off and Leave stay under HR requests.</span></div>
              <button className="secondary-button" type="button" onClick={addOverride}>Add Exception</button>
            </div>

            {manualOverrides.length ? <div className="schedule-override-list streamlined-overrides">{manualOverrides.map((row) => {
              const index = overrides.indexOf(row)
              return (
                <div className="schedule-override-row" key={`${row.schedule_date}-${index}`}>
                  <input type="date" value={row.schedule_date || ''} onChange={(e) => updateOverride(index, 'schedule_date', e.target.value)} />
                  <SearchableSelect value={row.schedule_type} onChange={(e) => updateOverride(index, 'schedule_type', e.target.value)}><option value="DAY_OFF">Day Off</option><option value="WORKDAY">Workday</option></SearchableSelect>
                  <label className="schedule-hours-field"><input type="number" min="0" max="24" step="0.25" disabled={row.schedule_type !== 'WORKDAY'} value={Number(row.expected_minutes || 0) / 60} onChange={(e) => updateOverride(index, 'expected_minutes', Math.round(Number(e.target.value || 0) * 60))} aria-label="Expected Hours" /><span>hrs</span></label>
                  <input placeholder="Notes" value={row.notes || ''} onChange={(e) => updateOverride(index, 'notes', e.target.value)} />
                  <button className="secondary-button" type="button" onClick={() => setOverrides((current) => current.filter((_, i) => i !== index))}>Remove</button>
                </div>
              )
            })}</div> : <div className="empty-state compact-empty">No admin schedule exceptions.</div>}
          </section>

          {generatedOverrides.length ? <section className="schedule-section generated-schedule-section">
            <div className="schedule-section-heading"><div><strong>Approved HR Requests</strong><span>Read-only Day Off / Leave schedule effects generated by HR approval.</span></div></div>
            <div className="schedule-generated-list">{generatedOverrides.map((row) => (
              <div className="schedule-generated-row" key={`generated-${row.id || row.schedule_date}`}>
                <strong>{row.schedule_date}</strong>
                <span className="status-pill neutral">{row.schedule_type === 'DAY_OFF' ? 'DAY OFF' : row.schedule_type}</span>
                <span>{row.notes || 'Approved HR request'}</span>
              </div>
            ))}</div>
          </section> : null}

          <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Schedule'}</button></div>
        </form>
      )}
    </Modal>
  )
}
