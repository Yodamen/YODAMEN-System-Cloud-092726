import SearchableSelect from '../../components/common/SearchableSelect'
import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function PayslipDetail({ row }) {
  if (!row) return null
  return <div className="page-stack">
    <section className="metric-grid"><article className="metric-card"><span>Gross Compensation</span><strong>{formatPeso(row.gross_compensation)}</strong></article><article className="metric-card"><span>Adjustments</span><strong>{formatPeso(row.adjustments_total)}</strong></article><article className="metric-card"><span>Cash Advance</span><strong>{formatPeso(row.cash_advance_deductions)}</strong></article><article className="metric-card"><span>Prior Payroll Recovery</span><strong>{formatPeso(row.payroll_recovery_deductions)}</strong></article><article className="metric-card"><span>Statutory Deductions</span><strong>{formatPeso(row.statutory_employee_deductions)}</strong></article><article className="metric-card"><span>Net Pay</span><strong>{formatPeso(row.net_pay)}</strong></article></section>
    <div className="table-wrap"><table className="data-table"><tbody><tr><th>Hourly Basic Pay</th><td>{formatPeso(row.hourly_basic_pay)}</td></tr><tr><th>OT Pay</th><td>{formatPeso(row.ot_pay)}</td></tr><tr><th>Commission</th><td>{formatPeso(row.commission_amount)}</td></tr><tr><th>Guaranteed Top-Up</th><td>{formatPeso(row.guaranteed_top_up)}</td></tr><tr><th>Gross Compensation</th><td><strong>{formatPeso(row.gross_compensation)}</strong></td></tr><tr><th>Payroll Adjustments</th><td>{formatPeso(row.adjustments_total)}</td></tr><tr><th>Cash Advance Deductions</th><td>{formatPeso(row.cash_advance_deductions)}</td></tr><tr><th>Prior Payroll Recovery</th><td><strong>{formatPeso(row.payroll_recovery_deductions)}</strong>{(row.payroll_recoveries || []).map((recovery) => <span key={recovery.id || recovery.code} className="table-subline">{recovery.code || 'Recovery'}{recovery.source_label ? ` · ${recovery.source_label}` : ''}{recovery.original_payroll_code ? ` · from ${recovery.original_payroll_code}` : ''}</span>)}</td></tr><tr><th>SSS Employee Share</th><td>{formatPeso(row.sss_employee)}</td></tr><tr><th>PhilHealth Employee Share</th><td>{formatPeso(row.philhealth_employee)}</td></tr><tr><th>Pag-IBIG Employee Share</th><td>{formatPeso(row.pagibig_employee)}</td></tr><tr><th>Total Statutory Deductions</th><td>{formatPeso(row.statutory_employee_deductions)}</td></tr><tr><th>Net Pay</th><td><strong>{formatPeso(row.net_pay)}</strong></td></tr></tbody></table></div>
  </div>
}

export default function PayslipsPage() {
  const [data, setData] = useState(null)
  const [personnelId, setPersonnelId] = useState('')
  const [selected, setSelected] = useState(null)
  const [message, setMessage] = useState('')

  async function load(nextPersonnelId = personnelId) {
    const response = await api.get('/v1/hr/payslips', { params: { personnel_id: nextPersonnelId || undefined } })
    setData(response.data)
  }

  useEffect(() => { load('').catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Payslips.'))) }, [])

  return <div className="page-stack">
    <div className="page-heading"><div><p className="eyebrow">HR & Payroll</p><h1 className="live-page-title">Payslips <PageRefreshButton label="Refresh payslips" onRefresh={() => load()} /></h1><p>Approved and paid payroll lines by personnel.</p></div></div>
    {message ? <div className="form-alert error">{message}</div> : null}
    <section className="content-card"><form className="attendance-filter-grid" onSubmit={(e) => { e.preventDefault(); load() }}><label className="form-field"><span>Personnel</span><SearchableSelect value={personnelId} onChange={(e) => setPersonnelId(e.target.value)}><option value="">All Personnel</option>{(data?.personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label><div className="attendance-filter-actions"><button className="primary-button">Apply</button><button className="secondary-button" type="button" onClick={() => { setPersonnelId(''); load('') }}>Reset</button></div></form></section>
    <section className="content-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>Payroll</th><th>Personnel</th><th>Period</th><th>Pay Date</th><th>Gross</th><th>Cash Advance</th><th>Prior Recovery</th><th>Statutory</th><th>Net Pay</th><th>Status</th><th></th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.id}><td>{row.payroll_code}</td><td><strong>{row.personnel?.name}</strong><span className="table-subline">{row.personnel?.code}</span></td><td>{formatDisplayDate(row.period_start)} → {formatDisplayDate(row.period_end)}</td><td>{formatDisplayDate(row.pay_date)}</td><td>{formatPeso(row.gross_compensation)}</td><td>{formatPeso(row.cash_advance_deductions)}</td><td>{formatPeso(row.payroll_recovery_deductions)}</td><td>{formatPeso(row.statutory_employee_deductions)}</td><td><strong>{formatPeso(row.net_pay)}</strong></td><td><span className={`status-pill ${['PAID','APPROVED'].includes(row.status) ? 'success' : 'warning'}`}>{row.status}</span></td><td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => setSelected(row) }]} /></td></tr>)}</tbody></table></div>{!(data?.rows || []).length ? <div className="empty-state">No approved or paid payslips found.</div> : null}</section>
    {selected ? <Modal wide title={`Payslip · ${selected.personnel?.name}`} subtitle={`${selected.payroll_code} · ${formatDisplayDate(selected.period_start)} → ${formatDisplayDate(selected.period_end)}`} onClose={() => setSelected(null)} headerAction={<span className={`status-pill ${['PAID','APPROVED'].includes(selected.status) ? 'success' : 'warning'}`}>{selected.status}</span>}><PayslipDetail row={selected} /></Modal> : null}
  </div>
}
