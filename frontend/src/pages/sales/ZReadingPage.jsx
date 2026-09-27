import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatPeso } from '../../utils/formatters'
import './sales-update79.css'

function localDateValue() {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function dateTimeText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

export default function ZReadingPage() {
  const { bootstrap, user, hasPermission } = useAuth()
  const canClose = hasPermission('sales', 'EDIT')
  const allowedBranches = useMemo(() => {
    const branches = bootstrap?.branches || []
    if (!user?.default_branch?.code) return branches
    return branches.filter((branch) => branch.code === user.default_branch.code)
  }, [bootstrap, user])
  const defaultBranchId = allowedBranches[0]?.id ? String(allowedBranches[0].id) : ''
  const [filters, setFilters] = useState({ branch_id: '', date: localDateValue() })
  const [data, setData] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [closeConfirm, setCloseConfirm] = useState(false)
  const [reopenOpen, setReopenOpen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')

  const load = useCallback(async (nextFilters) => {
    if (!nextFilters.branch_id) {
      setData(null)
      return
    }
    setMessage('')
    const response = await api.get('/v1/z-readings/preview', { params: { branch_id: nextFilters.branch_id, date: nextFilters.date } })
    setData(response.data)
  }, [])

  useEffect(() => {
    if (!defaultBranchId || filters.branch_id) return undefined

    const next = { ...filters, branch_id: defaultBranchId }
    const timer = window.setTimeout(() => {
      setFilters(next)
      setLoading(true)
      load(next)
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Z-Reading.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [defaultBranchId, filters, load])

  async function apply(event) {
    event.preventDefault()
    setLoading(true)
    try {
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Z-Reading.'))
    } finally {
      setLoading(false)
    }
  }

  async function closeReading() {
    setSaving(true)
    setMessage('')
    try {
      await api.post('/v1/z-readings/close', { branch_id: Number(filters.branch_id), business_date: filters.date })
      setCloseConfirm(false)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to close Z-Reading.'))
    } finally {
      setSaving(false)
    }
  }

  async function reopenReading(event) {
    event.preventDefault()
    if (!data?.z_reading?.id) return
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/z-readings/${data.z_reading.id}/reopen`, { reason: reopenReason })
      setReopenOpen(false)
      setReopenReason('')
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to reopen Z-Reading.'))
    } finally {
      setSaving(false)
    }
  }

  const preview = data?.preview
  const summary = preview?.summary || {}
  const reading = data?.z_reading
  const status = reading?.status || 'OPEN'

  return (
    <div className="page-stack sales-report-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Z-Reading <PageRefreshButton label="Refresh Z-Reading" disabled={loading || saving || !filters.branch_id} onRefresh={() => load(filters)} /></h1>
          <p className="page-subtitle">Review and close finalized sales for one branch and business date.</p>
        </div>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <form className="z-reading-filters" onSubmit={apply}>
          <label className="booking-filter-field"><span>Branch</span><SearchableSelect required value={filters.branch_id} onChange={(event) => setFilters((current) => ({ ...current, branch_id: event.target.value }))}><option value="">Select branch</option>{allowedBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
          <label className="booking-filter-field"><span>Business Date</span><input required type="date" value={filters.date} onChange={(event) => setFilters((current) => ({ ...current, date: event.target.value }))} /></label>
          <div className="sales-filter-actions"><button className="primary-button compact-button" type="submit" disabled={loading || !filters.branch_id}>Apply</button></div>
        </form>
      </section>

      {preview ? (
        <>
          <section className="content-card z-reading-status-card">
            <div>
              <span className="z-reading-label">{preview.branch?.name} · {preview.business_date}</span>
              <div className="z-reading-status-line"><span className={`status-pill ${status === 'CLOSED' ? 'success' : status === 'REOPENED' ? 'warning' : 'neutral'}`}>{status}</span>{reading?.closed_at ? <small>Closed {dateTimeText(reading.closed_at)}</small> : <small>Business date remains open.</small>}{reading?.reopen_reason ? <small>Reopened: {reading.reopen_reason}</small> : null}</div>
            </div>
            {canClose ? <div className="z-reading-actions">{status === 'CLOSED' ? <button className="secondary-button compact-button" type="button" onClick={() => setReopenOpen(true)}>Reopen</button> : <button className="primary-button compact-button" type="button" onClick={() => setCloseConfirm(true)}>Close Day</button>}</div> : null}
          </section>

          <section className="sales-metric-grid">
            <article className="content-card sales-metric-card"><span>Net Sales</span><strong>{formatPeso(summary.net_sales || 0)}</strong><small>{Number(summary.transactions || 0)} transaction(s)</small></article>
            <article className="content-card sales-metric-card"><span>Gross Sales</span><strong>{formatPeso(summary.gross_sales || 0)}</strong><small>Discounts {formatPeso(summary.discounts || 0)}</small></article>
            <article className="content-card sales-metric-card"><span>Payments Received</span><strong>{formatPeso(summary.payments_received || 0)}</strong><small>Collected on this date</small></article>
            <article className="content-card sales-metric-card"><span>Outstanding</span><strong>{formatPeso(summary.outstanding_balance || 0)}</strong><small>Average sale {formatPeso(summary.average_transaction || 0)}</small></article>
          </section>

          <div className="sales-report-two-column">
            <section className="content-card"><div className="section-heading compact-section-heading"><div><h2>Payment Methods</h2></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Method</th><th>Payments</th><th>Amount</th></tr></thead><tbody>{(preview.payment_methods || []).map((row) => <tr key={row.payment_method}><td><strong>{row.payment_method}</strong></td><td>{row.payments}</td><td><strong>{formatPeso(row.amount)}</strong></td></tr>)}{!(preview.payment_methods || []).length ? <tr><td colSpan="3"><div className="empty-state compact-empty">No received payments.</div></td></tr> : null}</tbody></table></div></section>
            <section className="content-card"><div className="section-heading compact-section-heading"><div><h2>Sales by Channel</h2></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Channel</th><th>Transactions</th><th>Net Sales</th></tr></thead><tbody>{(preview.channels || []).map((row) => <tr key={row.channel}><td><strong>{row.channel.replaceAll('_', ' ')}</strong></td><td>{row.transactions}</td><td><strong>{formatPeso(row.net_sales)}</strong></td></tr>)}{!(preview.channels || []).length ? <tr><td colSpan="3"><div className="empty-state compact-empty">No finalized sales.</div></td></tr> : null}</tbody></table></div></section>
          </div>

          <section className="content-card"><div className="section-heading compact-section-heading"><div><h2>Item Summary</h2></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Type</th><th>Quantity</th><th>Net Sales</th></tr></thead><tbody>{(preview.item_types || []).map((row) => <tr key={row.item_type}><td><strong>{row.item_type.replaceAll('_', ' ')}</strong></td><td>{row.quantity}</td><td><strong>{formatPeso(row.net_sales)}</strong></td></tr>)}{!(preview.item_types || []).length ? <tr><td colSpan="3"><div className="empty-state compact-empty">No finalized sale items.</div></td></tr> : null}</tbody></table></div></section>
        </>
      ) : !loading ? <section className="content-card"><div className="empty-state">Select a branch and business date to view the Z-Reading.</div></section> : null}

      {closeConfirm ? <ActionConfirmationModal title="Close Z-Reading" message="Close this business date using the sales snapshot currently shown?" confirmLabel="Close Day" busy={saving} details={[{ label: 'Branch', value: preview?.branch?.name || '—' }, { label: 'Business Date', value: preview?.business_date || filters.date }, { label: 'Net Sales', value: formatPeso(summary.net_sales || 0) }, { label: 'Payments Received', value: formatPeso(summary.payments_received || 0) }]} onCancel={() => setCloseConfirm(false)} onConfirm={closeReading} /> : null}

      {reopenOpen ? <Modal title="Reopen Z-Reading" subtitle="The reason is required and will be retained in the audit trail." onClose={() => setReopenOpen(false)}><form className="day-off-reschedule-form" onSubmit={reopenReading}><label className="form-field"><span>Reason</span><textarea required rows="4" value={reopenReason} onChange={(event) => setReopenReason(event.target.value)} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReopenOpen(false)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Reopening...' : 'Reopen Z-Reading'}</button></div></form></Modal> : null}
    </div>
  )
}
