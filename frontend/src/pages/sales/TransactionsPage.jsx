import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import ServiceList from '../../components/common/ServiceList'
import { formatDisplayDate } from '../../utils/formatters'

function money(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
}

function saleServices(sale) {
  if (Array.isArray(sale.services) && sale.services.length) return sale.services
  if (Array.isArray(sale.service_names)) return sale.service_names.map((name, index) => ({ id: `${sale.id}-service-${index}`, name }))
  if (Array.isArray(sale.items)) {
    return sale.items
      .filter((item) => String(item.item_type || '').toUpperCase() !== 'PRODUCT')
      .map((item) => ({ id: item.id, name: item.item_name }))
  }
  return []
}

export default function TransactionsPage() {
  const [sales, setSales] = useState([])
  const [search, setSearch] = useState('')
  const [activeTab, setActiveTab] = useState('PENDING')
  const [detail, setDetail] = useState(null)
  const [phase, setPhase] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)

  const loadPending = useCallback(async () => {
    const response = await api.get('/v1/sales/transactions/pending', { params: { search: search || undefined } })
    setSales(response.data.sales || [])
  }, [search])

  useEffect(() => {
    if (activeTab !== 'PENDING') return undefined
    const timer = window.setTimeout(() => {
      loadPending().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load pending transactions.'))).finally(() => setLoading(false))
    }, 120)
    return () => window.clearTimeout(timer)
  }, [activeTab, loadPending])

  async function refreshPageData() {
    setMessage('')
    if (activeTab !== 'PENDING') return
    try { await loadPending() }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to refresh transactions.')) }
  }

  async function openCheckout(sale) {
    setDetailLoading(true)
    setMessage('')
    try {
      const response = await api.get(`/v1/sales/transactions/${sale.id}/checkout`)
      setDetail(response.data.sale)
      setPhase(response.data.phase)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load transaction detail.'))
    } finally {
      setDetailLoading(false)
    }
  }

  function changeTab(tab) {
    setActiveTab(tab)
    setMessage('')
    if (tab !== 'PENDING') setSales([])
  }

  return (
    <div className="page-stack">
      <section className="page-heading live-page-heading">
        <h1 className="live-page-title">Transactions <PageRefreshButton label="Refresh transactions" disabled={loading || detailLoading} onRefresh={refreshPageData} /></h1>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <div className="booking-tabs">
        <button className={activeTab === 'PENDING' ? 'active' : ''} type="button" onClick={() => changeTab('PENDING')}>Pending</button>
        <button className={activeTab === 'ON_ACCOUNT' ? 'active' : ''} type="button" onClick={() => changeTab('ON_ACCOUNT')}>On Account</button>
        <button className={activeTab === 'PAID' ? 'active' : ''} type="button" onClick={() => changeTab('PAID')}>Paid</button>
        <button className={activeTab === 'SERVICE_CREDIT' ? 'active' : ''} type="button" onClick={() => changeTab('SERVICE_CREDIT')}>Service Credit</button>
      </div>

      <section className="content-card booking-list-card transactions-card">
        {activeTab === 'PENDING' ? (
          <>
            <div className="booking-filter-panel transaction-search-panel">
              <label className="booking-filter-field booking-search-field">Search<input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Customer or Booking ID" /></label>
            </div>
            {loading ? <div className="empty-state">Loading transactions...</div> : (
              <div className="table-wrap">
                <table className="data-table transactions-table">
                  <colgroup>
                    <col className="transaction-col-booking" />
                    <col className="transaction-col-customer" />
                    <col className="transaction-col-services" />
                    <col className="transaction-col-money" />
                    <col className="transaction-col-money" />
                    <col className="transaction-col-money" />
                    <col className="transaction-col-action" />
                  </colgroup>
                  <thead><tr><th>Booking ID</th><th>Customer</th><th>Services</th><th className="money-column">Gross</th><th className="money-column">Payment</th><th className="money-column">Balance</th><th className="actions-column">Action</th></tr></thead>
                  <tbody>
                    {sales.map((sale) => (
                      <tr key={sale.id}>
                        <td><strong>{sale.booking?.code || '—'}</strong><span className="table-subline">{formatDisplayDate(sale.sale_date)}</span></td>
                        <td><strong>{sale.customer?.name || '—'}</strong><span className="table-subline">{sale.customer?.mobile_number || ''}</span></td>
                        <td><ServiceList services={saleServices(sale)} /></td>
                        <td className="money-cell">{money(sale.gross_amount)}</td>
                        <td className="money-cell">{money(sale.total_paid)}</td>
                        <td className="money-cell"><strong>{money(sale.outstanding_balance)}</strong></td>
                        <td className="action-cell"><button className="checkout-action-button" type="button" disabled={detailLoading} onClick={() => openCheckout(sale)}>Checkout</button></td>
                      </tr>
                    ))}
                    {!sales.length && <tr><td colSpan="7"><div className="empty-state">No pending transactions.</div></td></tr>}
                  </tbody>
                </table>
              </div>
            )}
          </>
        ) : <div className="empty-state">{activeTab === 'ON_ACCOUNT' ? 'On Account line settlement activates in Phase 4.' : activeTab === 'PAID' ? 'Paid line settlement activates in Phase 4.' : 'Service Credit settlement activates with the Package / Service Credit phase.'}</div>}
      </section>

      {detail && (
        <Modal title={`Checkout · ${detail.booking?.code || 'Booking'}`} subtitle="Pending parent transaction. Payment allocation and final confirmation activate in the next phase." onClose={() => { setDetail(null); setPhase(null) }} wide>
          <div className="checkout-reference-grid">
            <div><span>Booking ID</span><strong>{detail.booking?.code || '—'}</strong></div>
            <div><span>Sales ID</span><strong>{detail.code || '—'}</strong></div>
            <div><span>Transaction Date</span><strong>{formatDisplayDate(detail.sale_date)}</strong></div>
            <div><span>Customer</span><strong>{detail.customer?.name || '—'}</strong></div>
          </div>

          <div className="checkout-summary-grid">
            <div className="summary-card"><span>Gross</span><strong>{money(detail.gross_amount)}</strong></div>
            <div className="summary-card"><span>Payment</span><strong>{money(detail.total_paid)}</strong></div>
            <div className="summary-card"><span>Balance</span><strong>{money(detail.outstanding_balance)}</strong></div>
          </div>

          <div className="section-box">
            <div className="section-heading-row"><h3>Transaction Items</h3><span className="status-pill neutral">{detail.status}</span></div>
            <div className="table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>Type</th><th>Qty</th><th className="money-column">Unit Price</th><th className="money-column">Line Total</th></tr></thead><tbody>{detail.items?.map((item) => <tr key={item.id}><td><strong>{item.item_name}</strong>{item.assigned_personnel_name ? <span className="table-subline">{item.assigned_personnel_name}</span> : null}</td><td>{item.item_type}</td><td>{item.quantity}</td><td className="money-cell">{money(item.unit_price)}</td><td className="money-cell">{money(item.line_total)}</td></tr>)}</tbody></table></div>
          </div>

          <div className="section-box">
            <div className="section-heading-row"><h3>Payments</h3></div>
            {detail.payments?.length ? <div className="table-wrap"><table className="data-table"><thead><tr><th>Type</th><th>Method</th><th>Reference</th><th>Date</th><th className="money-column">Amount</th></tr></thead><tbody>{detail.payments.map((payment) => <tr key={payment.id}><td>{payment.type}</td><td>{payment.method || '—'}</td><td>{payment.reference_no || '—'}</td><td>{formatDisplayDate(payment.payment_date)}</td><td className="money-cell">{money(payment.amount)}</td></tr>)}</tbody></table></div> : <div className="inline-empty">No cash has been received for this transaction yet.</div>}
          </div>

          {phase?.message ? <div className="form-alert info">{phase.message}</div> : null}
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => { setDetail(null); setPhase(null) }}>Close</button><button className="primary-button" type="button" disabled>Finalize Checkout · Phase 4</button></div>
        </Modal>
      )}
    </div>
  )
}
