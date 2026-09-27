import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function statusLabel(status) {
  return String(status || '').replaceAll('_', ' ')
}

function statusClass(status) {
  const value = String(status || '').toUpperCase()
  if (value === 'ACTIVE') return 'success'
  if (value === 'PENDING_CHECKOUT') return 'warning'
  return 'neutral'
}

function PackageFinancialModal({ enrollmentId, onClose }) {
  const [detail, setDetail] = useState(null)
  const [message, setMessage] = useState('')

  useEffect(() => {
    let active = true
    api.get(`/v1/package-enrollments/${enrollmentId}/financials`)
      .then((response) => { if (active) setDetail(response.data.package) })
      .catch((error) => { if (active) setMessage(getApiErrorMessage(error, 'Unable to load package details.')) })
    return () => { active = false }
  }, [enrollmentId])

  return (
    <Modal
      title="Package Payment Monitoring"
      subtitle={detail ? `${detail.customer?.name || ''} · ${detail.service?.name || ''}` : enrollmentId}
      onClose={onClose}
      wide
    >
      {message ? <div className="form-alert error">{message}</div> : null}
      {!detail && !message ? <div className="empty-state">Loading package details...</div> : null}
      {detail ? (
        <div className="package-detail-stack">
          <section className="package-summary-grid">
            <div className="package-summary-item"><span>Package Price</span><strong>{formatPeso(detail.package_price)}</strong></div>
            <div className="package-summary-item"><span>Collected</span><strong>{formatPeso(detail.total_paid)}</strong></div>
            <div className="package-summary-item"><span>Balance</span><strong>{formatPeso(detail.balance)}</strong></div>
            <div className="package-summary-item"><span>Purchased</span><strong>{detail.purchased}</strong></div>
            <div className="package-summary-item"><span>Used</span><strong>{detail.used}</strong></div>
            <div className="package-summary-item"><span>Remaining</span><strong>{detail.remaining}</strong></div>
          </section>

          <section className="section-box package-origin-box">
            <div className="package-origin-row"><span>Package ID</span><strong>{detail.enrollment_code}</strong></div>
            <div className="package-origin-row"><span>Booking ID</span><strong>{detail.booking?.code || '—'}</strong></div>
            <div className="package-origin-row"><span>Sale ID</span><strong>{detail.origin_sale?.code || 'Pending Checkout'}</strong></div>
            <div className="package-origin-row"><span>Status</span><strong>{statusLabel(detail.status)}</strong></div>
          </section>

          <section className="section-box">
            <div className="section-heading-row"><h3>Payment History</h3></div>
            <div className="table-wrap">
              <table className="data-table package-payment-history-table">
                <thead><tr><th>Date</th><th>Booking ID</th><th>Source</th><th>Payment ID</th><th>Method</th><th className="money-column">Amount</th></tr></thead>
                <tbody>
                  {[...(detail.payment_history || [])].sort((a, b) => {
                    const aTime = a.payment?.payment_date ? new Date(a.payment.payment_date).getTime() : 0
                    const bTime = b.payment?.payment_date ? new Date(b.payment.payment_date).getTime() : 0
                    if (bTime !== aTime) return bTime - aTime
                    return Number(b.application_id || 0) - Number(a.application_id || 0)
                  }).map((row) => (
                    <tr key={row.application_id}>
                      <td>{formatDisplayDate(row.payment?.payment_date)}</td>
                      <td><strong>{row.booking?.code || '—'}</strong></td>
                      <td><strong>{statusLabel(row.payment?.payment_type || 'Payment')}</strong><span className="table-subline">{statusLabel(row.application_type)}</span></td>
                      <td>{row.payment?.code || '—'}</td>
                      <td>{row.payment?.method || '—'}</td>
                      <td className="money-cell"><strong>{formatPeso(row.amount)}</strong></td>
                    </tr>
                  ))}
                  {!detail.payment_history?.length ? <tr><td colSpan="6"><div className="empty-state">No received package payments yet.</div></td></tr> : null}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      ) : null}
    </Modal>
  )
}

export default function ServiceBalancesPage() {
  const [rows, setRows] = useState([])
  const [statuses, setStatuses] = useState([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ALL')
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [selectedEnrollment, setSelectedEnrollment] = useState(null)

  const load = useCallback(async () => {
    const response = await api.get('/v1/service-balances', {
      params: { search: search || undefined, status: status === 'ALL' ? undefined : status },
    })
    setRows(response.data.balances || [])
    setStatuses(response.data.statuses || [])
  }, [search, status])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setLoading(true)
      setMessage('')
      load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load service balances.'))).finally(() => setLoading(false))
    }, 120)
    return () => window.clearTimeout(timer)
  }, [load])

  async function refreshPageData() {
    setMessage('')
    try { await load() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to refresh service balances.')) }
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Service Balances <PageRefreshButton label="Refresh service balances" disabled={loading} onRefresh={refreshPageData} /></h1>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card service-balances-card">
        <div className="booking-filter-panel service-balance-filters">
          <label className="booking-filter-field booking-search-field">Search<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Customer, service, Booking ID or Package ID" /></label>
          <label className="booking-filter-field">Status<SearchableSelect value={status} onChange={(event) => setStatus(event.target.value)}>{(statuses.length ? statuses : [{ code: 'ALL', label: 'All' }]).map((option) => <option key={option.code} value={option.code}>{option.label}</option>)}</SearchableSelect></label>
        </div>

        {loading ? <div className="empty-state">Loading service balances...</div> : (
          <div className="table-wrap">
            <table className="data-table service-balances-table">
              <thead><tr><th>Customer</th><th>Service</th><th className="money-column">Purchased</th><th className="money-column">Used</th><th className="money-column">Remaining</th><th className="money-column">Collected</th><th className="money-column">Balance</th><th>Status</th></tr></thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="clickable-table-row" tabIndex="0" onClick={() => setSelectedEnrollment(row.id)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setSelectedEnrollment(row.id) } }}>
                    <td><strong>{row.customer?.name || '—'}</strong><span className="table-subline">{row.customer?.mobile_number || row.customer?.code || ''}</span></td>
                    <td><strong>{row.service?.name || '—'}</strong><span className="table-subline">{row.enrollment_code}{row.booking?.code ? ` · ${row.booking.code}` : ''}</span></td>
                    <td className="money-cell">{row.purchased}</td>
                    <td className="money-cell">{row.used}</td>
                    <td className="money-cell"><strong>{row.remaining}</strong></td>
                    <td className="money-cell"><strong>{formatPeso(row.total_paid)}</strong></td>
                    <td className="money-cell">{formatPeso(row.balance)}</td>
                    <td><span className={`status-pill ${statusClass(row.status)}`}>{statusLabel(row.status)}</span></td>
                  </tr>
                ))}
                {!rows.length ? <tr><td colSpan="8"><div className="empty-state">No multi-session package balances yet.</div></td></tr> : null}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {selectedEnrollment ? <PackageFinancialModal enrollmentId={selectedEnrollment} onClose={() => setSelectedEnrollment(null)} /> : null}
    </div>
  )
}
