import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import CheckoutModal from '../../components/sales/CheckoutModal'
import TransactionActionModal from '../../components/sales/TransactionActionModal'
import ReversalWorkflowModal from '../../components/sales/ReversalWorkflowModal'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function statusLabel(value) {
  return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function reversedTransactionType(row) {
  const correctionStatus = String(row?.correction_status || '').toUpperCase()
  const historicalStatus = String(row?.historical_status || '').toUpperCase()

  if (correctionStatus === 'REMOVED' || historicalStatus === 'REMOVED_BY_CORRECTION') return 'Correction'
  return 'Reversal'
}

function reversedTransactionTone(row) {
  return reversedTransactionType(row) === 'Correction' ? 'info' : 'danger'
}

function saleServices(sale) {
  if (Array.isArray(sale.services) && sale.services.length) return sale.services
  return []
}


function TransactionServiceStack({ services = [] }) {
  if (!services.length) return <span className="service-list-empty">—</span>

  return (
    <div className="transaction-service-stack">
      {services.map((service, index) => (
        <div className="transaction-service-cell" key={service.id || `${service.service_name || service.name || 'service'}-${index}`}>
          <strong>{service.service_name || service.name || 'Service'}</strong>
          {service.assigned_personnel_name ? <span className="table-subline">{service.assigned_personnel_name}</span> : null}
          {service.is_multi_session && service.package_code ? <span className="table-subline">{service.package_code}</span> : null}
        </div>
      ))}
    </div>
  )
}

function bookingCollectionTotal(row) {
  const explicit = Number(row?.booking_collection_total || 0)
  if (explicit > 0.009) return explicit
  return Number(row?.booking_advance_payment_total || 0)
    + Number(row?.booking_interim_payment_total || 0)
    + Number(row?.booking_checkout_payment_total || 0)
}

function paymentTypeLabel(type) {
  const normalized = String(type || '').toUpperCase()
  if (normalized === 'ADVANCE') return 'Advance'
  if (normalized === 'INTERIM') return 'Interim'
  if (normalized === 'CHECKOUT') return 'Checkout Payment'
  if (normalized === 'COLLECTION') return 'Collection'
  if (normalized === 'MIXED') return 'Mixed Receipt'
  return normalized ? normalized.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Payment'
}

function BookingPaymentContext({ sale }) {
  const advance = Number(sale?.booking_advance_payment_total || 0)
  const interim = Number(sale?.booking_interim_payment_total || 0)
  const checkout = Number(sale?.booking_checkout_payment_total || 0)
  const bookingCollection = bookingCollectionTotal(sale)
  const displayedTotal = bookingCollection > 0.009 ? bookingCollection : Number(sale?.payments_received || 0)

  return (
    <div className="transaction-payment-context">
      <strong>{formatPeso(displayedTotal)}</strong>
      {advance > 0 ? <span className="table-subline">Advance</span> : null}
      {interim > 0 ? <span className="table-subline">Interim</span> : null}
      {checkout > 0 ? <span className="table-subline">Checkout Payment</span> : null}
    </div>
  )
}

function PaymentHistoryTable({ payments, fallbackBookingCode, emptyMessage }) {
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Booking ID</th>
            <th>Payment No.</th>
            <th>Type</th>
            <th>Method</th>
            <th>Reference</th>
            <th className="money-column">Amount</th>
          </tr>
        </thead>
        <tbody>
          {(payments || []).map((payment, index) => (
            <tr key={`${payment.payment_id || 'payment'}-${payment.application_type || payment.payment_type}-${index}`}>
              <td>{formatDisplayDate(payment.payment_date)}</td>
              <td><strong>{payment.booking?.code || fallbackBookingCode || '—'}</strong></td>
              <td>{payment.payment_code || '—'}</td>
              <td>{paymentTypeLabel(payment.application_type || payment.payment_type)}</td>
              <td>{payment.payment_method || '—'}</td>
              <td>{payment.reference_no || '—'}</td>
              <td className="money-cell"><strong>{formatPeso(payment.amount || 0)}</strong></td>
            </tr>
          ))}
          {!(payments || []).length ? (
            <tr><td colSpan="7"><div className="empty-state">{emptyMessage}</div></td></tr>
          ) : null}
        </tbody>
      </table>
    </div>
  )
}

function PaymentDetailsModal({ row, onClose }) {
  const currentPayments = Array.isArray(row?.payment_details) ? row.payment_details : []
  const packageHistories = Array.isArray(row?.package_histories) ? row.package_histories : []
  const itemLabel = Array.isArray(row?.item_names) && row.item_names.length
    ? row.item_names.join(', ')
    : 'Transaction'

  return (
    <Modal
      title="Payment Details"
      subtitle={`${row?.booking?.code || 'Booking'} · ${itemLabel}`}
      onClose={onClose}
      wide
    >
      <div className="checkout-simple-summary">
        <div>
          <span>This Booking Payment</span>
          <strong>{formatPeso(row?.payment_amount || 0)}</strong>
        </div>
        <div>
          <span>Booking Amount</span>
          <strong>{formatPeso(row?.amount || 0)}</strong>
        </div>
        <div>
          <span>Balance</span>
          <strong>{formatPeso(row?.balance_amount || 0)}</strong>
        </div>
      </div>

      <div className="transaction-payment-history-section">
        <div className="section-heading-row"><h3>This Booking</h3></div>
        <PaymentHistoryTable
          payments={currentPayments}
          fallbackBookingCode={row?.booking?.code}
          emptyMessage="No payment allocation belongs to this Booking."
        />
      </div>

      {packageHistories.map((packageHistory) => (
        <div className="transaction-payment-history-section" key={`package-${packageHistory.package_enrollment_id}`}>
          <div className="section-heading-row">
            <div>
              <h3>Earlier Package Payments · {packageHistory.package_name || 'Package'}</h3>
              <span className="table-subline">
                Origin Booking {packageHistory.origin_booking?.code || '—'} · Current Package Balance {formatPeso(packageHistory.current_balance || 0)}
              </span>
            </div>
          </div>
          <PaymentHistoryTable
            payments={packageHistory.payments}
            fallbackBookingCode={packageHistory.origin_booking?.code}
            emptyMessage="No earlier payment history exists for this package before the current Booking."
          />
        </div>
      ))}

      <div className="modal-actions">
        <button className="secondary-button" type="button" onClick={onClose}>Close</button>
      </div>
    </Modal>
  )
}

function transactionFilterParams({ startDate, endDate, staffName, bookingId, packageId, customerName, serviceName }) {
  return {
    start_date: startDate || undefined,
    end_date: endDate || undefined,
    staff_name: staffName || undefined,
    booking_id: bookingId || undefined,
    package_id: packageId || undefined,
    customer_name: customerName || undefined,
    service_name: serviceName || undefined,
  }
}

export default function TransactionsPage() {
  const { hasPermission, user } = useAuth()
  const [sales, setSales] = useState([])
  const [lines, setLines] = useState([])
  const [approvals, setApprovals] = useState([])
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [staffName, setStaffName] = useState('')
  const [bookingId, setBookingId] = useState('')
  const [packageId, setPackageId] = useState('')
  const [customerName, setCustomerName] = useState('')
  const [serviceName, setServiceName] = useState('')
  const [activeTab, setActiveTab] = useState('PENDING')
  const [checkout, setCheckout] = useState(null)
  const [paymentDetail, setPaymentDetail] = useState(null)
  const [transactionAction, setTransactionAction] = useState(null)
  const [reversalWorkflow, setReversalWorkflow] = useState(false)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)

  const loadPending = useCallback(async () => {
    const [salesResponse, approvalsResponse] = await Promise.all([
      api.get('/v1/sales/transactions/pending', {
        params: transactionFilterParams({ startDate, endDate, staffName, bookingId, packageId, customerName, serviceName }),
      }),
      api.get('/v1/sales/transaction-action-requests/pending'),
    ])
    setSales(salesResponse.data.sales || [])
    setApprovals(approvalsResponse.data.requests || [])
  }, [startDate, endDate, staffName, bookingId, packageId, customerName, serviceName])

  const loadLines = useCallback(async (classification = activeTab) => {
    const response = await api.get('/v1/sales/transactions/settlement-lines', {
      params: {
        classification,
        ...transactionFilterParams({ startDate, endDate, staffName, bookingId, packageId, customerName, serviceName }),
      },
    })
    setLines(response.data.lines || [])
  }, [activeTab, startDate, endDate, staffName, bookingId, packageId, customerName, serviceName])

  const loadCurrent = useCallback(async () => {
    if (activeTab === 'PENDING') await loadPending()
    else await loadLines(activeTab)
  }, [activeTab, loadLines, loadPending])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setLoading(true)
      loadCurrent().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load transactions.'))).finally(() => setLoading(false))
    }, 100)
    return () => window.clearTimeout(timer)
  }, [loadCurrent])

  async function refreshPageData() {
    setMessage('')
    try { await loadCurrent() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to refresh transactions.')) }
  }

  async function openCheckout(sale) {
    setDetailLoading(true)
    setMessage('')
    try {
      const response = await api.get(`/v1/sales/transactions/${sale.id}/checkout`)
      setCheckout({ detail: response.data.sale, allocation: response.data.allocation })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load checkout.'))
    } finally {
      setDetailLoading(false)
    }
  }


  async function openTransactionAction(saleId, actionType = 'CORRECTION', preselectedItemId = null) {
    setDetailLoading(true)
    setMessage('')
    try {
      const response = await api.get(`/v1/sales/transactions/${saleId}/action-context`)
      setTransactionAction({
        context: response.data,
        actionType,
        preselectedItemId,
      })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load transaction action controls.'))
    } finally {
      setDetailLoading(false)
    }
  }

  async function openApprovalRequest(request, reviewMode) {
    setDetailLoading(true)
    setMessage('')
    try {
      const response = await api.get(`/v1/sales/transactions/${request.sale_id}/action-context`)
      setTransactionAction({ context: response.data, actionType: request.action_type, preselectedItemId: null, reviewMode })
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load approval request.'))
    } finally {
      setDetailLoading(false)
    }
  }

  async function handleTransactionActionChanged(successMessage) {
    setTransactionAction(null)
    setMessage(successMessage || 'Transaction action updated.')

    if (String(successMessage || '').toLowerCase().includes('submitted')) {
      setActiveTab('PENDING')
      setSales([])
      setLines([])
      setApprovals([])
      await loadPending()
      return
    }

    await loadCurrent()
  }


  async function handleFinalized(sale) {
    setCheckout(null)
    const classification = sale.transaction_classification || sale.status
    const nextTab = classification === 'ON_ACCOUNT'
      ? 'ON_ACCOUNT'
      : classification === 'SERVICE_CREDIT'
        ? 'SERVICE_CREDIT'
        : 'PAID'
    setActiveTab(nextTab)
    setMessage(`Checkout finalized as ${nextTab.replaceAll('_', ' ')}.`)
  }

  function changeTab(tab) {
    setActiveTab(tab)
    setMessage('')
    setSales([])
    setLines([])
    setApprovals([])
  }

  function resetFilters() {
    setStartDate('')
    setEndDate('')
    setStaffName('')
    setBookingId('')
    setPackageId('')
    setCustomerName('')
    setServiceName('')
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading transactions-page-heading">
        <div className="transactions-heading-row">
          <h1 className="live-page-title">Transactions <PageRefreshButton label="Refresh transactions" disabled={loading || detailLoading} onRefresh={refreshPageData} /></h1>
          {hasPermission('sales', 'REVERSE') ? <button className="primary-button transaction-reversal-button" type="button" onClick={() => setReversalWorkflow(true)}>Reverse</button> : null}
        </div>
      </section>

      {message ? <div className={`form-alert ${message.toLowerCase().includes('unable') ? 'error' : 'success'}`}>{message}</div> : null}

      <div className="booking-tabs">
        <button className={activeTab === 'PENDING' ? 'active' : ''} type="button" onClick={() => changeTab('PENDING')}>Pending</button>
        <button className={activeTab === 'ON_ACCOUNT' ? 'active' : ''} type="button" onClick={() => changeTab('ON_ACCOUNT')}>On Account</button>
        <button className={activeTab === 'PAID' ? 'active' : ''} type="button" onClick={() => changeTab('PAID')}>Paid</button>
        <button className={activeTab === 'SERVICE_CREDIT' ? 'active' : ''} type="button" onClick={() => changeTab('SERVICE_CREDIT')}>Service Credit</button>
        <button className={activeTab === 'REVERSED' ? 'active' : ''} type="button" onClick={() => changeTab('REVERSED')}>Reversed</button>
      </div>

      <section className="content-card booking-list-card transactions-card">
        <div className="booking-filter-panel transaction-filter-panel">
          <label className="booking-filter-field">From<input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} /></label>
          <label className="booking-filter-field">To<input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} /></label>
          <label className="booking-filter-field">Staff Name<input type="search" value={staffName} onChange={(event) => setStaffName(event.target.value)} placeholder="Staff name" /></label>
          <label className="booking-filter-field">Booking ID<input type="search" value={bookingId} onChange={(event) => setBookingId(event.target.value)} placeholder="BKG-..." /></label>
          <label className="booking-filter-field">Package ID<input type="search" value={packageId} onChange={(event) => setPackageId(event.target.value)} placeholder="PKE-..." /></label>
          <label className="booking-filter-field">Customer Name<input type="search" value={customerName} onChange={(event) => setCustomerName(event.target.value)} placeholder="Customer name" /></label>
          <label className="booking-filter-field">Service Name<input type="search" value={serviceName} onChange={(event) => setServiceName(event.target.value)} placeholder="Service name" /></label>
          <div className="booking-filter-actions"><button type="button" className="secondary-button mini" onClick={resetFilters}>Reset Filters</button></div>
        </div>

        {loading ? <div className="empty-state">Loading transactions...</div> : activeTab === 'PENDING' ? (
          <div className="table-wrap">
            <table className="data-table transactions-table pending-unified-table">
              <colgroup><col className="transaction-col-booking" /><col className="transaction-col-customer" /><col className="transaction-col-services" /><col className="transaction-col-status" /><col className="transaction-col-money" /><col className="transaction-col-money" /><col className="transaction-col-money" /><col className="transaction-col-action" /></colgroup>
              <thead><tr><th>Booking ID</th><th>Customer</th><th>Service</th><th>Status</th><th className="money-column">Amount</th><th className="money-column">Payment</th><th className="money-column">Balance</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {approvals.map((request) => (
                  <tr key={`approval-${request.id}`} className="pending-approval-row">
                    <td><strong>{request.booking_code || '—'}</strong><span className="table-subline">{formatDisplayDate(request.requested_at)}</span></td>
                    <td><strong>{request.customer_name || 'Walk-in Customer'}</strong></td>
                    <td><strong>{request.sale_code || request.code}</strong><span className="table-subline">{String(request.action_type || '').replaceAll('_', ' ')} · {request.reason_label || 'Approval Request'}</span></td>
                    <td><strong>Pending Approval</strong><span className="table-subline">{request.code}</span></td>
                    <td className="money-cell"><strong>{formatPeso(request.sale_amount || 0)}</strong></td>
                    <td className="money-cell"><span className="table-subline">Approval pending</span></td>
                    <td className="money-cell"><strong>{formatPeso(request.outstanding_balance || 0)}</strong></td>
                    <td className="action-cell">{request.can_review ? <ActionMenu items={[
                      { label: 'Approve', disabled: detailLoading, onClick: () => openApprovalRequest(request, 'approve') },
                      { label: 'Reject', disabled: detailLoading, danger: true, onClick: () => openApprovalRequest(request, 'reject') },
                    ]} /> : <span className="table-subline">Awaiting approver</span>}</td>
                  </tr>
                ))}
                {sales.filter((sale) => !approvals.some((request) => Number(request.sale_id) === Number(sale.id))).map((sale) => <tr key={`sale-${sale.id}`}>
                  <td><strong>{sale.booking?.code || '—'}</strong><span className="table-subline">{formatDisplayDate(sale.sale_date)}</span></td>
                  <td><strong>{sale.customer?.name || '—'}</strong> <CustomerSegmentTag customerClass={sale.customer?.customer_class} customerType={sale.customer?.customer_type || sale.customer?.segment} typeColor={sale.customer?.customer_type_color} /><span className="table-subline">{sale.customer?.mobile_number || ''}</span></td>
                  <td><TransactionServiceStack services={saleServices(sale)} /></td>
                  <td><strong>{statusLabel(sale.status)}</strong><span className="table-subline">{sale.code}</span></td>
                  <td className="money-cell"><strong>{formatPeso(sale.gross_amount)}</strong></td>
                  <td className="money-cell"><BookingPaymentContext sale={sale} /></td>
                  <td className="money-cell"><strong>{formatPeso(sale.outstanding_balance)}</strong></td>
                  <td className="action-cell"><ActionMenu items={[
                    { label: 'Checkout', disabled: detailLoading, onClick: () => openCheckout(sale) },
                    hasPermission('sales', 'CORRECT') ? { label: 'Correction', disabled: detailLoading, onClick: () => openTransactionAction(sale.id, 'CORRECTION') } : null,
                  ]} /></td>
                </tr>)}
                {!approvals.length && !sales.length && <tr><td colSpan="8"><div className="empty-state">No pending transactions or approval requests.</div></td></tr>}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="table-wrap">
            <table className={`data-table settlement-lines-table ${activeTab === 'REVERSED' ? 'is-reversed' : ''}`}>
              <colgroup>
                <col className="settlement-col-booking" />
                <col className="settlement-col-customer" />
                <col className="settlement-col-service" />
                <col className="settlement-col-money" />
                <col className="settlement-col-money" />
                <col className="settlement-col-money" />
                {activeTab === 'REVERSED' ? <col className="settlement-col-approved" /> : null}
                <col className="settlement-col-action" />
              </colgroup>
              <thead><tr><th>Booking ID</th><th>Customer</th><th>Service</th><th className="money-column">Amount</th><th className="money-column">{activeTab === 'REVERSED' ? 'Refunded' : 'Payment'}</th><th className="money-column">{activeTab === 'REVERSED' ? 'Refund Due' : 'Balance'}</th>{activeTab === 'REVERSED' ? <th>Approved By</th> : null}<th className="actions-column">{activeTab === 'REVERSED' ? 'Type' : 'Action'}</th></tr></thead>
              <tbody>
                {lines.map((row) => {
                  const hasDetails = (Array.isArray(row.payment_details) && row.payment_details.length > 0)
                    || (Array.isArray(row.package_histories) && row.package_histories.length > 0)
                  return (
                    <tr key={`${activeTab}-${row.id}`}>
                      <td><strong>{row.booking?.code || '—'}</strong><span className="table-subline">{formatDisplayDate(row.transaction_date)}</span></td>
                      <td><strong>{row.customer?.name || 'Walk-in Customer'}</strong> <CustomerSegmentTag customerClass={row.customer?.customer_class} customerType={row.customer?.customer_type || row.customer?.segment} typeColor={row.customer?.customer_type_color} /><span className="table-subline">{row.customer?.mobile_number || ''}</span></td>
                      <td>
                        <div className="transaction-service-cell">
                          {(row.item_names || []).map((name, index) => (
                            <strong key={`${row.id}-item-${index}`}>{name}</strong>
                          ))}
                          {row.assigned_personnel_name ? <span className="table-subline">{row.assigned_personnel_name}</span> : null}
                          {row.is_multi_session && row.package_code ? <span className="table-subline">{row.package_code}</span> : null}
                          {row.historical_status === 'REMOVED_BY_CORRECTION' ? <>
                            <span className="table-subline">Original Qty {row.original_quantity || 0} · {formatPeso(row.original_amount || 0)}</span>
                            {row.correction_history?.request_code ? <span className="table-subline">{row.correction_history.request_code} · {row.correction_history.reason || 'Correction'}</span> : null}
                          </> : null}
                        </div>
                      </td>
                      <td className="money-cell">
                        <strong>{formatPeso(activeTab === 'REVERSED' ? (row.original_amount || 0) : (row.amount || 0))}</strong>
                      </td>
                      <td className="money-cell">
                        {activeTab === 'REVERSED' ? (
                          <strong>{formatPeso(row.cash_refunded_amount || 0)}</strong>
                        ) : (
                          <div className="transaction-payment-context">
                            {hasDetails ? (
                              <button className="link-button transaction-payment-link" type="button" onClick={() => setPaymentDetail(row)}>{formatPeso(row.payment_amount || 0)}</button>
                            ) : <strong>{formatPeso(row.payment_amount || 0)}</strong>}
                            {activeTab === 'ON_ACCOUNT' && row.payment_type_summary ? <span className="table-subline">{row.payment_type_summary}</span> : null}
                          </div>
                        )}
                      </td>
                      <td className="money-cell">
                        {activeTab === 'REVERSED' && Number(row.refund_due_amount || 0) > 0.009 && hasPermission('sales', 'REFUND') ? (
                          <button className="link-button transaction-payment-link danger-text" type="button" disabled={detailLoading} onClick={() => openTransactionAction(row.sale_id, 'REFUND', row.sale_item_id)}>{formatPeso(row.refund_due_amount || 0)}</button>
                        ) : <strong>{formatPeso(activeTab === 'REVERSED' ? (row.refund_due_amount || 0) : (row.balance_amount || 0))}</strong>}
                        {activeTab !== 'REVERSED' && Number(row.refund_due_amount || 0) > 0.009 ? <span className="table-subline danger-text">Refund Due {formatPeso(row.refund_due_amount || 0)}</span> : null}
                      </td>
                      {activeTab === 'REVERSED' ? <td><strong>{row.approved_by || '—'}</strong></td> : null}
                      <td className="action-cell">{activeTab === 'REVERSED' ? <span className={`status-pill ${reversedTransactionTone(row)}`}>{reversedTransactionType(row)}</span> : <ActionMenu items={[
                        hasPermission('sales', 'CORRECT') && row.historical_status !== 'REMOVED_BY_CORRECTION' ? { label: 'Correction', disabled: detailLoading, onClick: () => openTransactionAction(row.sale_id, 'CORRECTION', row.sale_item_id) } : null,
                      ]} />}</td>
                    </tr>
                  )
                })}
                {!lines.length && <tr><td colSpan={activeTab === 'REVERSED' ? 8 : 7}><div className="empty-state">No {activeTab.replaceAll('_', ' ').toLowerCase()} transactions.</div></td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {checkout ? <CheckoutModal detail={checkout.detail} allocation={checkout.allocation} onClose={() => setCheckout(null)} onFinalized={handleFinalized} /> : null}
      {paymentDetail ? <PaymentDetailsModal row={paymentDetail} onClose={() => setPaymentDetail(null)} /> : null}
      {transactionAction ? <TransactionActionModal
        context={transactionAction.context}
        initialAction={transactionAction.actionType}
        preselectedItemId={transactionAction.preselectedItemId}
        currentUser={user}
        canApprove={hasPermission('sales', 'APPROVE_ACTION')}
        reviewMode={transactionAction.reviewMode || null}
        onClose={() => setTransactionAction(null)}
        onChanged={handleTransactionActionChanged}
      /> : null}
      {reversalWorkflow ? <ReversalWorkflowModal onClose={() => setReversalWorkflow(false)} onSubmitted={async (successMessage) => { setReversalWorkflow(false); setActiveTab('PENDING'); setMessage(successMessage); await loadPending() }} /> : null}
    </div>
  )
}
