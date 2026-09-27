import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import ActionMenu from '../../components/common/ActionMenu'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import WorkflowReasonSelect from '../../components/common/WorkflowReasonSelect'
import './leave.css'

function dateText(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`))
}

function yearStart() {
  const d = new Date()
  return `${d.getFullYear()}-01-01`
}

function yearEnd() {
  const d = new Date()
  return `${d.getFullYear()}-12-31`
}

function statusClass(status) {
  if (status === 'APPROVED') return 'success'
  if (status === 'PENDING') return 'warning'
  if (status === 'REJECTED' || status === 'CANCELLED') return 'danger'
  return 'neutral'
}

export default function LeaveManagementPage() {
  const [filters, setFilters] = useState({ status: 'ALL', personnel_id: '', from: yearStart(), to: yearEnd() })
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [detailRequest, setDetailRequest] = useState(null)
  const [reviewModal, setReviewModal] = useState(null)
  const [balancePersonnelId, setBalancePersonnelId] = useState('')
  const [balanceYear, setBalanceYear] = useState(new Date().getFullYear())
  const [balanceData, setBalanceData] = useState(null)
  const [balanceEdit, setBalanceEdit] = useState(null)

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/hr/leave-requests', { params: next })
    setData(response.data)
  }, [filters])

  useEffect(() => {
    load(filters)
      .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Leave Management.')))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function apply(event) {
    event.preventDefault()
    setLoading(true)
    setMessage('')
    try { await load(filters) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load requests.')) }
    finally { setLoading(false) }
  }

  async function resetFilters() {
    const next = { status: 'ALL', personnel_id: '', from: yearStart(), to: yearEnd() }
    setFilters(next)
    setLoading(true)
    setMessage('')
    try { await load(next) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load requests.')) }
    finally { setLoading(false) }
  }

  function review(row, action) {
    setDetailRequest(null)
    setReviewModal({ row, action, review_reason_reference_id: '', notes: '' })
  }

  async function submitReview(event) {
    event.preventDefault()
    if (!reviewModal) return
    setMessage('')
    try {
      await api.post(`/v1/hr/leave-requests/${reviewModal.row.id}/${reviewModal.action}`, { notes: reviewModal.notes || null, ...(reviewModal.action === 'reject' ? { review_reason_reference_id: Number(reviewModal.review_reason_reference_id) } : {}) })
      setReviewModal(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, `Unable to ${reviewModal.action} request.`))
    }
  }

  async function openBalances() {
    if (!balancePersonnelId) return
    setMessage('')
    setBalanceEdit(null)
    try {
      const response = await api.get(`/v1/hr/leave-balances/${balancePersonnelId}`, { params: { year: balanceYear } })
      setBalanceData(response.data)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load leave balances.'))
    }
  }

  async function changeBalanceYear(nextYear) {
    setBalanceYear(nextYear)
    if (!balancePersonnelId) return
    try {
      const response = await api.get(`/v1/hr/leave-balances/${balancePersonnelId}`, { params: { year: nextYear } })
      setBalanceData(response.data)
      setBalanceEdit(null)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load leave balances.'))
    }
  }

  async function saveBalance(event) {
    event.preventDefault()
    try {
      await api.put(`/v1/hr/leave-balances/${balanceEdit.personnel_id}/${balanceEdit.leave_type_id}/${balanceYear}`, {
        entitled_days: Number(balanceEdit.entitled_days || 0),
        adjustment_days: Number(balanceEdit.adjustment_days || 0),
        notes: balanceEdit.notes || null,
      })
      setBalanceEdit(null)
      await openBalances()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update leave balance.'))
    }
  }

  return (
    <div className="page-stack">
      <div className="page-heading">
        <div>
          <p className="eyebrow">HR & Payroll</p>
          <h1 className="live-page-title">Leave Management <PageRefreshButton label="Refresh leave management" disabled={loading} onRefresh={() => load(filters)} /></h1>
          <p>Approve leave and day-off requests, and maintain employee leave credits.</p>
        </div>
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <form className="leave-filter-grid" onSubmit={apply}>
          <label className="form-field"><span>From</span><input type="date" value={filters.from} onChange={(e) => setFilters((current) => ({ ...current, from: e.target.value }))} /></label>
          <label className="form-field"><span>To</span><input type="date" value={filters.to} onChange={(e) => setFilters((current) => ({ ...current, to: e.target.value }))} /></label>
          <label className="form-field"><span>Status</span><SearchableSelect value={filters.status} onChange={(e) => setFilters((current) => ({ ...current, status: e.target.value }))}><option value="ALL">All Statuses</option><option value="PENDING">Pending</option><option value="APPROVED">Approved</option><option value="REJECTED">Rejected</option><option value="CANCELLED">Cancelled</option></SearchableSelect></label>
          <label className="form-field"><span>Personnel</span><SearchableSelect value={filters.personnel_id} onChange={(e) => setFilters((current) => ({ ...current, personnel_id: e.target.value }))}><option value="">All Personnel</option>{(data?.personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <div className="leave-filter-actions"><button className="primary-button" type="submit">Apply</button><button className="secondary-button" type="button" onClick={resetFilters}>Reset</button></div>
        </form>
      </section>

      <section className="content-card">
        <div className="section-heading"><div><h2>Requests</h2></div></div>
        {loading ? <div className="empty-state">Loading requests...</div> : null}
        {!loading && !(data?.rows || []).length ? <div className="empty-state">No matching requests.</div> : null}
        {(data?.rows || []).length ? (
          <div className="table-wrap"><table className="data-table"><thead><tr><th>Request</th><th>Personnel</th><th>Dates</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{data.rows.map((row) => (
            <tr key={row.id}>
              <td><strong>{row.code}</strong><span className="table-subline">{row.request_type === 'LEAVE' ? row.leave_type?.name : 'Day Off'}</span></td>
              <td>{row.personnel?.name}<span className="table-subline">{row.personnel?.code}</span></td>
              <td>{dateText(row.start_date)}{row.end_date !== row.start_date ? ` → ${dateText(row.end_date)}` : ''}<span className="table-subline">{row.duration_type.replaceAll('_', ' ')}</span></td>
              <td><span className={`status-pill ${statusClass(row.status)}`}>{row.status}</span></td>
              <td className="action-cell"><ActionMenu items={[{ label: 'View', onClick: () => setDetailRequest(row) }]} /></td>
            </tr>
          ))}</tbody></table></div>
        ) : null}
      </section>

      <section className="content-card">
        <div className="section-heading"><div><h2>Leave Balances</h2><p>Select an employee to view and maintain leave credits.</p></div></div>
        <div className="leave-balance-selector">
          <label className="form-field"><span>Employee</span><SearchableSelect value={balancePersonnelId} onChange={(e) => { setBalancePersonnelId(e.target.value); setBalanceData(null); setBalanceEdit(null) }}><option value="">Select employee</option>{(data?.personnel || []).filter((row) => row.paid_leave_entitled).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <button className="primary-button leave-balance-view-button" type="button" disabled={!balancePersonnelId} onClick={openBalances}>View</button>
        </div>
      </section>

      {detailRequest ? <Modal title="Leave Request Details" subtitle={`${detailRequest.code} · ${detailRequest.personnel?.name || ''}`} onClose={() => setDetailRequest(null)}>
        <div className="page-stack">
          <div className="leave-request-detail-grid">
            <div><span>Request Type</span><strong>{detailRequest.request_type === 'LEAVE' ? detailRequest.leave_type?.name : 'Day Off'}</strong></div>
            <div><span>Status</span><strong>{detailRequest.status}</strong></div>
            <div><span>Start Date</span><strong>{dateText(detailRequest.start_date)}</strong></div>
            <div><span>End Date</span><strong>{dateText(detailRequest.end_date)}</strong></div>
            <div><span>Duration</span><strong>{detailRequest.duration_type.replaceAll('_', ' ')}</strong></div>
            <div><span>Approved Units</span><strong>{Number(detailRequest.approved_units || 0).toFixed(2)}</strong></div>
            <div className="full-span"><span>Reason</span><strong>{detailRequest.reason || '—'}</strong></div>
            <div className="full-span"><span>Review Category</span><strong>{detailRequest.review_reason_category?.name || '—'}</strong></div><div className="full-span"><span>Review Notes</span><strong>{detailRequest.review_notes || '—'}</strong></div>
            <div><span>Reviewed By</span><strong>{detailRequest.reviewed_by || '—'}</strong></div>
            <div><span>Reviewed At</span><strong>{detailRequest.reviewed_at ? new Date(detailRequest.reviewed_at).toLocaleString('en-PH') : '—'}</strong></div>
          </div>
          <div className="modal-actions">
            <button className="secondary-button" type="button" onClick={() => setDetailRequest(null)}>Close</button>
            {detailRequest.status === 'PENDING' ? <><button className="danger-button" type="button" onClick={() => review(detailRequest, 'reject')}>Reject</button><button className="primary-button" type="button" onClick={() => review(detailRequest, 'approve')}>Approve</button></> : null}
          </div>
        </div>
      </Modal> : null}

      {reviewModal ? <Modal title={`${reviewModal.action === 'approve' ? 'Approve' : 'Reject'} Leave Request`} subtitle={`${reviewModal.row.code} · ${reviewModal.row.personnel?.name || ''}`} onClose={() => setReviewModal(null)}>
        <form className="page-stack" onSubmit={submitReview}>
          <div className="leave-review-summary"><div><span>Request</span><strong>{reviewModal.row.request_type === 'LEAVE' ? reviewModal.row.leave_type?.name : 'Day Off'}</strong></div><div><span>Dates</span><strong>{dateText(reviewModal.row.start_date)}{reviewModal.row.end_date !== reviewModal.row.start_date ? ` → ${dateText(reviewModal.row.end_date)}` : ''}</strong></div><div><span>Reason</span><strong>{reviewModal.row.reason}</strong></div></div>
          {reviewModal.action === 'reject' ? <WorkflowReasonSelect context="APPROVAL_REJECTION" label="Rejection Category" value={reviewModal.review_reason_reference_id} onChange={(e) => setReviewModal((current) => ({ ...current, review_reason_reference_id: e.target.value }))} /> : null}
          <label className="form-field"><span>{reviewModal.action === 'approve' ? 'Approval Notes' : 'Rejection Details'} {reviewModal.action === 'approve' ? <small>(optional)</small> : null}</span><textarea required={reviewModal.action === 'reject'} minLength={reviewModal.action === 'reject' ? 3 : undefined} rows="4" value={reviewModal.notes} onChange={(e) => setReviewModal((current) => ({ ...current, notes: e.target.value }))} /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setReviewModal(null)}>Cancel</button><button className={reviewModal.action === 'approve' ? 'primary-button' : 'danger-button'} type="submit">{reviewModal.action === 'approve' ? 'Approve Request' : 'Reject Request'}</button></div>
        </form>
      </Modal> : null}

      {balanceData ? <Modal wide title={`Leave Balances · ${balanceData.personnel?.name || 'Employee'}`} subtitle={`Year ${balanceYear}`} onClose={() => { setBalanceData(null); setBalanceEdit(null) }}>
        {!balanceEdit ? <div className="page-stack">
          <div className="leave-balance-year-row"><label className="form-field"><span>Balance Year</span><input type="number" min="2020" max="2100" value={balanceYear} onChange={(e) => changeBalanceYear(Number(e.target.value) || new Date().getFullYear())} /></label></div>
          <div className="table-wrap"><table className="data-table leave-balance-table"><thead><tr><th>Leave Type</th><th>Entitled</th><th>Adjustments</th><th>Used</th><th>Available</th><th className="actions-column">Action</th></tr></thead><tbody>{(balanceData.balances || []).map((row) => <tr key={`${row.personnel_id}-${row.leave_type_id}`}><td><strong>{row.leave_type_name}</strong></td><td>{Number(row.entitled_days).toFixed(2)}</td><td>{Number(row.adjustment_days).toFixed(2)}</td><td>{Number(row.used_days).toFixed(2)}</td><td><strong>{Number(row.available_days).toFixed(2)}</strong></td><td className="action-cell"><ActionMenu items={[row.consumes_balance ? { label: 'Edit', onClick: () => setBalanceEdit({ ...row }) } : null]} /></td></tr>)}{!(balanceData.balances || []).length ? <tr><td colSpan="6"><div className="empty-state">No leave balances found for this employee.</div></td></tr> : null}</tbody></table></div>
        </div> : <form className="page-stack" onSubmit={saveBalance}>
          <div className="section-heading-row"><div><h3>{balanceEdit.leave_type_name}</h3><span className="table-subline">{balanceEdit.personnel_name}</span></div><button className="secondary-button" type="button" onClick={() => setBalanceEdit(null)}>Back to Balances</button></div>
          <label className="form-field"><span>Entitled Days</span><input type="number" min="0" step="0.25" value={balanceEdit.entitled_days} onChange={(e) => setBalanceEdit((current) => ({ ...current, entitled_days: e.target.value }))} /></label>
          <label className="form-field"><span>Adjustment Days</span><input type="number" step="0.25" value={balanceEdit.adjustment_days} onChange={(e) => setBalanceEdit((current) => ({ ...current, adjustment_days: e.target.value }))} /></label>
          <label className="form-field"><span>Notes</span><textarea rows="3" value={balanceEdit.notes || ''} onChange={(e) => setBalanceEdit((current) => ({ ...current, notes: e.target.value }))} /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setBalanceEdit(null)}>Cancel Edit</button><button className="primary-button" type="submit">Save Balance</button></div>
        </form>}
      </Modal> : null}
    </div>
  )
}
