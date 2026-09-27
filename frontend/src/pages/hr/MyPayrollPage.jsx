import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'
import './my-payroll.css'

function sourceStatus(status) {
  if (status === 'OPEN') return 'Open'
  if (status === 'IN_PAYROLL_DRAFT') return 'In Draft'
  if (status === 'APPROVED') return 'Approved'
  if (status === 'PAID') return 'Paid'
  if (status === 'RECOVERY_PENDING') return 'Recovery Pending'
  return String(status || '—').replaceAll('_', ' ')
}

function ruleText(personnel) {
  if (!personnel) return ''
  if (personnel.compensation_rule === 'HOURLY_PLUS_COMMISSION') {
    return `Hourly basic pay at ${formatPeso(personnel.hourly_rate)}/hr + eligible OT + confirmed commission.`
  }
  if (personnel.compensation_rule === 'GUARANTEED_VS_COMMISSION') {
    return `Guaranteed daily pay of ${formatPeso(personnel.guaranteed_daily_pay)} versus confirmed commission, plus eligible OT.`
  }
  return 'Operational compensation is configured for this personnel record.'
}

export default function MyPayrollPage() {
  const [data, setData] = useState(null)
  const [selected, setSelected] = useState(null)
  const [message, setMessage] = useState('')

  async function load() {
    const response = await api.get('/v1/hr/my-payroll')
    setData(response.data)
  }

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load My Payroll.')))
  }, [])

  const summary = data?.summary || {}
  const running = data?.running_payroll || {}
  const cash = data?.cash_advance || {}

  return (
    <div className="page-stack my-payroll-page">
      <div className="page-heading">
        <div>
          <p className="eyebrow">HR & Payroll</p>
          <h1 className="live-page-title">My Payroll <PageRefreshButton label="Refresh my payroll" onRefresh={load} /></h1>
          <p>Your running compensation, confirmed commission activity, and approved payroll history.</p>
        </div>
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}

      {data?.personnel ? (
        <section className="content-card my-payroll-identity">
          <div>
            <strong>{data.personnel.name}</strong>
            <span>{data.personnel.position || 'Personnel'} · {data.personnel.code}</span>
          </div>
          <p>{ruleText(data.personnel)}</p>
        </section>
      ) : null}

      <section className="metric-grid my-payroll-summary-grid">
        <article className="metric-card"><span>Today Confirmed Commission</span><strong>{formatPeso(summary.today_confirmed_commission)}</strong><small>Sales-confirmed service and retail transactions today.</small></article>
        <article className="metric-card"><span>Today Projected Compensation</span><strong>{formatPeso(summary.today_projected_compensation)}</strong><small>Current daily compensation projection.</small></article>
        <article className="metric-card"><span>Running Commission</span><strong>{formatPeso(summary.running_commission)}</strong><small>Included in the current unpaid payroll position.</small></article>
        <article className="metric-card"><span>Projected Running Net</span><strong>{formatPeso(summary.projected_running_net)}</strong><small>After current adjustments, cash advance, and prior payroll recovery.</small></article>
        <article className="metric-card"><span>Outstanding Payroll Recovery</span><strong>{formatPeso(summary.outstanding_payroll_recovery)}</strong><small>Unrecovered commission/incentive corrections carried forward.</small></article>
      </section>

      <div className="my-payroll-two-column">
        <section className="content-card">
          <div className="section-heading"><div><h2>Running Payroll</h2><p>{running.payroll_code ? `${running.payroll_code} · ${running.payroll_status}` : 'Unpaid source records'}</p></div></div>
          <div className="my-payroll-stat-grid">
            <div><span>Basic / Guarantee Top-Up</span><strong>{formatPeso(running.basic_and_guarantee_top_up)}</strong></div>
            <div><span>Commission</span><strong>{formatPeso(running.commission)}</strong></div>
            <div><span>Additions</span><strong>{formatPeso(running.additions)}</strong></div>
            <div><span>Other Deductions</span><strong>{formatPeso(running.other_deductions)}</strong></div>
            <div><span>Prior Payroll Recovery</span><strong>{formatPeso(running.prior_payroll_recovery)}</strong></div>
          </div>
        </section>

        <section className="content-card">
          <div className="section-heading"><div><h2>Cash Advance</h2><p>Employee receivable</p></div></div>
          <div className="my-payroll-stat-grid">
            <div><span>Active Advances</span><strong>{cash.active_advances ?? 0}</strong></div>
            <div><span>Remaining Balance</span><strong>{formatPeso(cash.remaining_balance)}</strong></div>
            <div><span>Next Scheduled Deduction</span><strong>{formatPeso(cash.next_scheduled_deduction)}</strong></div>
            <div><span>Last Paid Payroll</span><strong>{cash.last_paid_payroll || '—'}</strong></div>
            <div><span>Last Pay Date</span><strong>{cash.last_pay_date ? formatDisplayDate(cash.last_pay_date) : '—'}</strong></div>
            <div><span>Payslip</span><strong>{cash.last_payslip_id ? 'Available' : '—'}</strong></div>
          </div>
        </section>
      </div>

      <section className="content-card">
        <div className="section-heading"><div><h2>Confirmed Commission Transactions</h2><p>Most recent financially confirmed service and retail commissions.</p></div></div>
        {(data?.commission_transactions || []).length ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Date</th><th>Item</th><th>References</th><th>Commission Basis</th><th>Commission</th><th>Payroll</th></tr></thead>
              <tbody>
                {data.commission_transactions.map((row) => (
                  <tr key={row.id}>
                    <td>{formatDisplayDate(row.date)}</td>
                    <td><strong>{row.item || row.service || 'Item'}</strong><span className="table-subline">{row.earning_type === 'RETAIL' ? 'Retail Purchase' : 'Service'}</span></td>
                    <td>{row.customer || '—'}<span className="table-subline">{[row.booking_code ? `Booking: ${row.booking_code}` : null, row.sale_code ? `Sale: ${row.sale_code}` : null].filter(Boolean).join(' · ') || '—'}</span></td>
                    <td>{formatPeso(row.service_value)}</td>
                    <td><strong>{formatPeso(row.commission)}</strong></td>
                    <td>{sourceStatus(row.status)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : <div className="empty-state">No confirmed commission transactions yet.</div>}
      </section>

      <section className="content-card">
        <div className="section-heading"><div><h2>Payroll History</h2><p>Approved and paid payslips.</p></div></div>
        {(data?.rows || []).length ? (
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Payroll</th><th>Period</th><th>Pay Date</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th className="actions-column">Action</th></tr></thead>
              <tbody>{data.rows.map((row) => <tr key={row.id}><td>{row.payroll_code}</td><td>{formatDisplayDate(row.period_start)} → {formatDisplayDate(row.period_end)}</td><td>{formatDisplayDate(row.pay_date)}</td><td>{formatPeso(row.gross_compensation)}</td><td>{formatPeso(Number(row.cash_advance_deductions || 0) + Number(row.payroll_recovery_deductions || 0) + Math.abs(Math.min(0, Number(row.adjustments_total || 0))))}</td><td><strong>{formatPeso(row.net_pay)}</strong></td><td><span className={`status-pill ${['PAID','APPROVED'].includes(row.status) ? 'success' : 'warning'}`}>{row.status}</span></td><td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => setSelected(row) }]} /></td></tr>)}</tbody>
            </table>
          </div>
        ) : <div className="empty-state">No approved or paid payroll records yet.</div>}
      </section>

      {selected ? (
        <Modal title={`My Payslip · ${selected.payroll_code}`} subtitle={`${formatDisplayDate(selected.period_start)} → ${formatDisplayDate(selected.period_end)}`} onClose={() => setSelected(null)}>
          <div className="page-stack">
            <section className="metric-grid"><article className="metric-card"><span>Gross</span><strong>{formatPeso(selected.gross_compensation)}</strong></article><article className="metric-card"><span>Net Pay</span><strong>{formatPeso(selected.net_pay)}</strong></article></section>
            <div className="table-wrap"><table className="data-table"><tbody><tr><th>Hourly Basic Pay</th><td>{formatPeso(selected.hourly_basic_pay)}</td></tr><tr><th>OT Pay</th><td>{formatPeso(selected.ot_pay)}</td></tr><tr><th>Commission</th><td>{formatPeso(selected.commission_amount)}</td></tr><tr><th>Guaranteed Top-Up</th><td>{formatPeso(selected.guaranteed_top_up)}</td></tr><tr><th>Adjustments</th><td>{formatPeso(selected.adjustments_total)}</td></tr><tr><th>Cash Advance Deductions</th><td>{formatPeso(selected.cash_advance_deductions)}</td></tr><tr><th>Prior Payroll Recovery</th><td><strong>{formatPeso(selected.payroll_recovery_deductions)}</strong>{(selected.payroll_recoveries || []).map((recovery) => <span key={recovery.id || recovery.code} className="table-subline">{recovery.code || 'Recovery'}{recovery.source_label ? ` · ${recovery.source_label}` : ''}{recovery.original_payroll_code ? ` · from ${recovery.original_payroll_code}` : ''}</span>)}</td></tr><tr><th>Net Pay</th><td><strong>{formatPeso(selected.net_pay)}</strong></td></tr></tbody></table></div>
          </div>
        </Modal>
      ) : null}
    </div>
  )
}
