import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'
import WorkScheduleModal from './WorkScheduleModal'
import './attendance.css'

function options(references, key) {
  return references?.[key] || []
}

function compensationSnapshot(source) {
  return {
    compensation_rule_code: source?.compensation_rule_code || source?.compensation_rule?.code || 'NONE',
    hourly_rate: Number(source?.hourly_rate || 0),
    ot_entitled: Boolean(source?.ot_entitled),
    ot_multiplier: source?.ot_multiplier === '' || source?.ot_multiplier == null ? null : Number(source.ot_multiplier),
    guaranteed_daily_pay: Number(source?.guaranteed_daily_pay || 0),
    compensation_effective_from: source?.compensation_effective_from || '',
  }
}

function compensationTermsChanged(form) {
  if (!form?._original_compensation) return false
  const current = compensationSnapshot(form)
  const original = form._original_compensation
  return current.compensation_rule_code !== original.compensation_rule_code
    || Math.abs(current.hourly_rate - original.hourly_rate) > 0.0001
    || current.ot_entitled !== original.ot_entitled
    || current.ot_multiplier !== original.ot_multiplier
    || Math.abs(current.guaranteed_daily_pay - original.guaranteed_daily_pay) > 0.0001
}

function displayDate(value) {
  if (!value) return ''
  return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(`${value}T00:00:00`))
}

function newPersonnel(references) {
  return {
    id: null,
    first_name: '',
    last_name: '',
    mobile_number: '',
    email: '',
    position_code: options(references, 'EMPLOYEE_POSITION')[0]?.code || 'BARBER',
    branch_id: '',
    employment_type_code: 'REGULAR',
    personnel_type_code: 'EMPLOYEE',
    profit_sharing_eligible: false,
    profit_sharing_percentage: 0,
    profit_sharing_effective_from: '',
    profit_sharing_effective_until: '',
    hourly_rate: 0,
    ot_entitled: false,
    ot_multiplier: '',
    compensation_rule_code: 'HOURLY_PLUS_COMMISSION',
    guaranteed_daily_pay: 0,
    compensation_effective_from: new Date().toISOString().slice(0, 10),
    compensation_effective_date_control: null,
    _original_compensation: null,
    can_perform_services: true,
    commission_eligible: true,
    paid_leave_entitled: true,
    sss_enabled: false,
    sss_number: '',
    philhealth_enabled: false,
    philhealth_number: '',
    pagibig_enabled: false,
    pagibig_number: '',
    hire_date: '',
    linked_user_id: '',
    status: 'ACTIVE',
    notes: '',
  }
}

export default function PersonnelPage() {
  const { hasPermission } = useAuth()
  const [personnel, setPersonnel] = useState([])
  const [references, setReferences] = useState({})
  const [branches, setBranches] = useState([])
  const [users, setUsers] = useState([])
  const [form, setForm] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [schedulePersonnel, setSchedulePersonnel] = useState(null)

  const load = useCallback(async () => {
    const response = await api.get('/v1/hr/personnel')
    setPersonnel(response.data.personnel || [])
    setReferences(response.data.references || {})
    setBranches(response.data.branches || [])
    setUsers(response.data.users || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      load()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load personnel.')))
        .finally(() => setLoading(false))
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  function openCreate() {
    setMessage('')
    setForm(newPersonnel(references))
  }

  function openEdit(record) {
    setMessage('')
    setForm({
      id: record.id,
      first_name: record.first_name,
      last_name: record.last_name,
      mobile_number: record.mobile_number || '',
      email: record.email || '',
      position_code: record.position?.code || '',
      branch_id: record.branch?.id || '',
      employment_type_code: record.employment_type?.code || '',
      personnel_type_code: record.personnel_type?.code || 'EMPLOYEE',
      profit_sharing_eligible: record.profit_sharing_eligible,
      profit_sharing_percentage: record.profit_sharing_percentage || 0,
      profit_sharing_effective_from: record.profit_sharing_effective_from || '',
      profit_sharing_effective_until: record.profit_sharing_effective_until || '',
      hourly_rate: record.hourly_rate || 0,
      ot_entitled: record.ot_entitled,
      ot_multiplier: record.ot_multiplier ?? '',
      compensation_rule_code: record.compensation_rule?.code || 'HOURLY_PLUS_COMMISSION',
      guaranteed_daily_pay: record.guaranteed_daily_pay || 0,
      compensation_effective_from: record.compensation_effective_from || new Date().toISOString().slice(0, 10),
      compensation_effective_date_control: record.compensation_effective_date_control || null,
      _original_compensation: compensationSnapshot(record),
      can_perform_services: record.can_perform_services,
      commission_eligible: record.commission_eligible,
      paid_leave_entitled: record.paid_leave_entitled !== false,
      sss_enabled: Boolean(record.sss_enabled),
      sss_number: record.sss_number || '',
      philhealth_enabled: Boolean(record.philhealth_enabled),
      philhealth_number: record.philhealth_number || '',
      pagibig_enabled: Boolean(record.pagibig_enabled),
      pagibig_number: record.pagibig_number || '',
      hire_date: record.hire_date || '',
      linked_user_id: record.linked_user?.id || '',
      status: record.status || 'ACTIVE',
      notes: record.notes || '',
    })
  }

  function updateForm(key, value) {
    setForm((current) => {
      const next = { ...current, [key]: value }

      if (key === 'position_code' && value === 'MANAGING_PARTNER') {
        next.personnel_type_code = 'PARTNER'
      }

      if (key === 'personnel_type_code' && value !== 'PARTNER') {
        next.profit_sharing_eligible = false
      }

      if (key === 'compensation_rule_code' && value === 'NONE') {
        next.hourly_rate = 0
        next.guaranteed_daily_pay = 0
        next.ot_entitled = false
        next.ot_multiplier = ''
      }

      const compensationKeys = ['compensation_rule_code', 'hourly_rate', 'ot_entitled', 'ot_multiplier', 'guaranteed_daily_pay']
      if (compensationKeys.includes(key) && next._original_compensation) {
        const changed = compensationTermsChanged(next)
        const earliest = next.compensation_effective_date_control?.earliest_effective_date
        if (changed && earliest && (!next.compensation_effective_from || next.compensation_effective_from < earliest)) {
          next.compensation_effective_from = earliest
        }
        if (!changed) {
          next.compensation_effective_from = next._original_compensation.compensation_effective_from
        }
      }

      return next
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      const payload = {
        ...form,
        branch_id: form.branch_id ? Number(form.branch_id) : null,
        linked_user_id: form.linked_user_id ? Number(form.linked_user_id) : null,
        profit_sharing_percentage: Number(form.profit_sharing_percentage || 0),
        hourly_rate: Number(form.hourly_rate || 0),
        ot_multiplier: form.ot_multiplier === '' ? null : Number(form.ot_multiplier),
        guaranteed_daily_pay: Number(form.guaranteed_daily_pay || 0),
        compensation_effective_from: form.compensation_effective_from || null,
        profit_sharing_effective_from: form.profit_sharing_effective_from || null,
        profit_sharing_effective_until: form.profit_sharing_effective_until || null,
        hire_date: form.hire_date || null,
        employment_type_code: form.personnel_type_code === 'PARTNER' ? null : form.employment_type_code,
      }

      if (form.id) {
        await api.put(`/v1/hr/personnel/${form.id}`, payload)
      } else {
        await api.post('/v1/hr/personnel', payload)
      }

      setForm(null)
      await load()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save personnel.'))
    } finally {
      setSaving(false)
    }
  }

  const isPartner = form?.personnel_type_code === 'PARTNER'
  const guaranteed = form?.compensation_rule_code === 'GUARANTEED_VS_COMMISSION'
  const noOperationalComp = form?.compensation_rule_code === 'NONE'
  const compensationChanged = compensationTermsChanged(form)
  const compensationControl = form?.compensation_effective_date_control
  const earliestCompensationDate = compensationChanged ? compensationControl?.earliest_effective_date : undefined

  return (
    <div className="page-stack">
      <section className="page-heading">
        <h1>Personnel</h1>
        {hasPermission('employees', 'CREATE') && (
          <button className="primary-button" type="button" onClick={openCreate}>+ Add Personnel</button>
        )}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        {loading ? (
          <div className="empty-state">Loading personnel...</div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Personnel</th>
                  <th>Type / Position</th>
                  <th>Branch</th>
                  <th>Operational Compensation</th>
                  <th>Profit Sharing</th>
                  <th>Status</th>
                  <th className="actions-column">Action</th>
                </tr>
              </thead>
              <tbody>
                {personnel.map((record) => (
                  <tr key={record.id}>
                    <td>
                      <strong>{record.full_name}</strong>
                      <small className="table-subline">{record.code}{record.linked_user ? ` · @${record.linked_user.username}` : ''}</small>
                    </td>
                    <td>
                      {record.personnel_type?.display_value || '—'}
                      <small className="table-subline">{record.position?.display_value || '—'}</small>
                    </td>
                    <td>{record.branch?.name || 'No specific branch'}</td>
                    <td>
                      {record.compensation_rule?.display_value || '—'}
                      <small className="table-subline">
                        {record.compensation_rule?.code === 'HOURLY_PLUS_COMMISSION'
                          ? `₱${Number(record.hourly_rate).toLocaleString('en-PH', { minimumFractionDigits: 2 })}/hr`
                          : record.compensation_rule?.code === 'GUARANTEED_VS_COMMISSION'
                            ? `₱${Number(record.guaranteed_daily_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })} guarantee`
                            : 'No operational compensation'}
                      </small>
                    </td>
                    <td>{record.profit_sharing_eligible ? `${record.profit_sharing_percentage}%` : 'Not eligible'}</td>
                    <td><span className={`status-pill ${record.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{record.status}</span></td>
                    <td className="action-cell"><ActionMenu items={[
                      hasPermission('employees', 'EDIT') ? { label: 'Edit', onClick: () => openEdit(record) } : null,
                      hasPermission('employees', 'EDIT') ? { label: 'Work Schedule', onClick: () => setSchedulePersonnel(record) } : null,
                    ]} /></td>
                  </tr>
                ))}
                {!personnel.length && (
                  <tr><td colSpan="7"><div className="empty-state">No personnel records yet.</div></td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {schedulePersonnel ? <WorkScheduleModal personnel={schedulePersonnel} onClose={() => setSchedulePersonnel(null)} /> : null}

      {form && (
        <Modal
          title={form.id ? 'Edit Personnel' : 'Add Personnel'}
          subtitle="Partner profit sharing is configured here. Profit Sharing runs remain a separate Accounting workflow."
          onClose={() => setForm(null)}
          wide
        >
          <form className="form-grid personnel-form-grid" onSubmit={save}>
            <label className="form-field">
              First Name
              <input value={form.first_name} onChange={(event) => updateForm('first_name', event.target.value)} required />
            </label>
            <label className="form-field">
              Last Name
              <input value={form.last_name} onChange={(event) => updateForm('last_name', event.target.value)} required />
            </label>
            <label className="form-field">
              Mobile Number
              <input value={form.mobile_number} onChange={(event) => updateForm('mobile_number', event.target.value)} />
            </label>
            <label className="form-field">
              Email
              <input type="email" value={form.email} onChange={(event) => updateForm('email', event.target.value)} />
            </label>

            <label className="form-field">
              Personnel Type
              <SearchableSelect value={form.personnel_type_code} onChange={(event) => updateForm('personnel_type_code', event.target.value)}>
                {options(references, 'PERSONNEL_TYPE').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </SearchableSelect>
            </label>

            <label className="form-field">
              Position
              <SearchableSelect value={form.position_code} onChange={(event) => updateForm('position_code', event.target.value)}>
                {options(references, 'EMPLOYEE_POSITION').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </SearchableSelect>
            </label>

            <label className="form-field">
              Branch
              <SearchableSelect value={form.branch_id} onChange={(event) => updateForm('branch_id', event.target.value)}>
                <option value="">No Specific Branch</option>
                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
              </SearchableSelect>
            </label>

            {!isPartner && (
              <label className="form-field">
                Employment Type
                <SearchableSelect value={form.employment_type_code} onChange={(event) => updateForm('employment_type_code', event.target.value)}>
                  {options(references, 'EMPLOYMENT_TYPE').map((option) => (
                    <option key={option.code} value={option.code}>{option.display_value}</option>
                  ))}
                </SearchableSelect>
              </label>
            )}

            <label className="form-field">
              Linked User
              <SearchableSelect value={form.linked_user_id} onChange={(event) => updateForm('linked_user_id', event.target.value)}>
                <option value="">No linked user</option>
                {users.map((user) => (
                  <option key={user.id} value={user.id}>{user.display_name} (@{user.username})</option>
                ))}
              </SearchableSelect>
            </label>

            <label className="form-field">
              Operational Compensation
              <SearchableSelect value={form.compensation_rule_code} onChange={(event) => updateForm('compensation_rule_code', event.target.value)}>
                {options(references, 'COMPENSATION_RULE').map((option) => (
                  <option key={option.code} value={option.code}>{option.display_value}</option>
                ))}
              </SearchableSelect>
            </label>

            <label className="form-field">
              Compensation Effective From
              <input
                type="date"
                value={form.compensation_effective_from || ''}
                min={earliestCompensationDate}
                onChange={(event) => updateForm('compensation_effective_from', event.target.value)}
              />
              {compensationChanged && compensationControl?.blocked_through ? (
                <small>Compensation activity already exists through {displayDate(compensationControl.blocked_through)}. Earliest effective date: {displayDate(compensationControl.earliest_effective_date)}. Future dates remain allowed.</small>
              ) : (
                <small>Required when compensation terms change. Existing payroll and earned compensation history will never be rewritten.</small>
              )}
            </label>

            {!noOperationalComp && !guaranteed && (
              <label className="form-field">
                Hourly Rate
                <input type="number" min="0" step="0.01" value={form.hourly_rate} onChange={(event) => updateForm('hourly_rate', event.target.value)} />
              </label>
            )}

            {guaranteed && (
              <label className="form-field">
                Guaranteed Daily Pay
                <input type="number" min="0" step="0.01" value={form.guaranteed_daily_pay} onChange={(event) => updateForm('guaranteed_daily_pay', event.target.value)} />
              </label>
            )}

            <label className="form-field">
              Hire / Start Date
              <input type="date" value={form.hire_date} onChange={(event) => updateForm('hire_date', event.target.value)} />
            </label>

            <label className="form-field">
              Status
              <SearchableSelect value={form.status} onChange={(event) => updateForm('status', event.target.value)}>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
              </SearchableSelect>
            </label>

            {!noOperationalComp && (
              <div className="form-span-2 section-box">
                <h3>Overtime Eligibility</h3>
                <div className="checkbox-grid">
                  <label className="checkbox-field">
                    <input type="checkbox" checked={form.ot_entitled} onChange={(event) => updateForm('ot_entitled', event.target.checked)} />
                    <span><strong>OT Entitled</strong><small>Approved OT hours may feed compensation.</small></span>
                  </label>
                  <label className="form-field">
                    OT Multiplier
                    <input type="number" min="0.001" step="0.001" disabled={!form.ot_entitled} value={form.ot_multiplier} onChange={(event) => updateForm('ot_multiplier', event.target.value)} />
                  </label>
                </div>
              </div>
            )}

            <div className="form-span-2 section-box">
              <h3>Leave Entitlement</h3>
              <label className="checkbox-field">
                <input type="checkbox" checked={form.paid_leave_entitled} onChange={(event) => updateForm('paid_leave_entitled', event.target.checked)} />
                <span><strong>Leave Entitled</strong><small>Controls whether this personnel can file Leave requests. Day Off requests remain available.</small></span>
              </label>
            </div>

            {form.personnel_type_code === 'EMPLOYEE' && (
              <div className="form-span-2 section-box">
                <h3>Statutory Benefits</h3>
                <p className="section-note">Enable only the government programs applicable to this personnel. Payroll fields and accounting payables are prepared for SSS, PhilHealth, and Pag-IBIG contribution posting.</p>
                <div className="statutory-benefits-grid">
                  <div className="statutory-benefit-card">
                    <label className="checkbox-field compact-checkbox-field">
                      <input type="checkbox" checked={form.sss_enabled} onChange={(event) => updateForm('sss_enabled', event.target.checked)} />
                      <span><strong>SSS</strong></span>
                    </label>
                    <label className="form-field"><span>SSS Number</span><input disabled={!form.sss_enabled} value={form.sss_number} onChange={(event) => updateForm('sss_number', event.target.value)} placeholder="Optional for setup" /></label>
                  </div>
                  <div className="statutory-benefit-card">
                    <label className="checkbox-field compact-checkbox-field">
                      <input type="checkbox" checked={form.philhealth_enabled} onChange={(event) => updateForm('philhealth_enabled', event.target.checked)} />
                      <span><strong>PhilHealth</strong></span>
                    </label>
                    <label className="form-field"><span>PhilHealth Number</span><input disabled={!form.philhealth_enabled} value={form.philhealth_number} onChange={(event) => updateForm('philhealth_number', event.target.value)} placeholder="Optional for setup" /></label>
                  </div>
                  <div className="statutory-benefit-card">
                    <label className="checkbox-field compact-checkbox-field">
                      <input type="checkbox" checked={form.pagibig_enabled} onChange={(event) => updateForm('pagibig_enabled', event.target.checked)} />
                      <span><strong>Pag-IBIG</strong></span>
                    </label>
                    <label className="form-field"><span>Pag-IBIG MID Number</span><input disabled={!form.pagibig_enabled} value={form.pagibig_number} onChange={(event) => updateForm('pagibig_number', event.target.value)} placeholder="Optional for setup" /></label>
                  </div>
                </div>
              </div>
            )}

            <div className="form-span-2 section-box">
              <h3>Service &amp; Commission Eligibility</h3>
              <div className="checkbox-grid">
                <label className="checkbox-field">
                  <input type="checkbox" checked={form.can_perform_services} onChange={(event) => updateForm('can_perform_services', event.target.checked)} />
                  <span><strong>Can Perform Services</strong><small>May be assigned to customer services.</small></span>
                </label>
                <label className="checkbox-field">
                  <input type="checkbox" checked={form.commission_eligible} onChange={(event) => updateForm('commission_eligible', event.target.checked)} />
                  <span><strong>Commission Eligible</strong><small>Confirmed rendered services may generate commission later.</small></span>
                </label>
              </div>
            </div>

            {isPartner && (
              <div className="form-span-2 section-box">
                <h3>Partner Profit Sharing</h3>
                <label className="checkbox-field">
                  <input type="checkbox" checked={form.profit_sharing_eligible} onChange={(event) => updateForm('profit_sharing_eligible', event.target.checked)} />
                  <span><strong>Profit Sharing Eligible</strong><small>Actual distributions will be generated under Accounting → Profit Sharing.</small></span>
                </label>

                {form.profit_sharing_eligible && (
                  <div className="form-grid nested-grid">
                    <label className="form-field">
                      Profit Sharing %
                      <input type="number" min="0.0001" max="100" step="any" value={form.profit_sharing_percentage} onChange={(event) => updateForm('profit_sharing_percentage', event.target.value)} />
                    </label>
                    <label className="form-field">
                      Effective From
                      <input type="date" value={form.profit_sharing_effective_from} onChange={(event) => updateForm('profit_sharing_effective_from', event.target.value)} />
                    </label>
                    <label className="form-field">
                      Effective Until
                      <input type="date" value={form.profit_sharing_effective_until} onChange={(event) => updateForm('profit_sharing_effective_until', event.target.value)} />
                    </label>
                  </div>
                )}
              </div>
            )}

            <label className="form-field form-span-2">
              Notes
              <textarea rows="3" value={form.notes} onChange={(event) => updateForm('notes', event.target.value)} />
            </label>

            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Personnel'}</button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
