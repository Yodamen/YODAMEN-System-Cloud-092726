import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'
import SupportingDocumentField from '../../components/common/SupportingDocumentField'
import { anyPaymentMethodRequiresProof, uploadSupportingDocuments } from '../../utils/supportingDocuments'

function newKey() {
  return window.crypto?.randomUUID ? window.crypto.randomUUID() : `retail-pos-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

const blankPayment = () => ({ payment_method_reference_id: '', amount: '', reference_no: '' })

function monthStart() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01` }
function todayValue() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}` }
const DEFAULT_HISTORY_FILTERS = { search: '', from: monthStart(), to: todayValue(), customer_id: '', personnel_id: '', status: 'ALL' }

function promotionCodes(value) {
  return [...new Set(String(value || '').toUpperCase().split(/[\s,;+]+/).map((code) => code.trim()).filter(Boolean))]
}

export default function RetailPosPage() {
  const { hasPermission } = useAuth()
  const [tab, setTab] = useState('sale')
  const [options, setOptions] = useState({ products: [], personnel: [], customers: [], payment_methods: [], branches: [], selected_branch: null })
  const [history, setHistory] = useState([])
  const [branchId, setBranchId] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [productSearch, setProductSearch] = useState('')
  const [cart, setCart] = useState([])
  const [discountCode, setDiscountCode] = useState('')
  const [discountPreview, setDiscountPreview] = useState(null)
  const [previewing, setPreviewing] = useState(false)
  const [payments, setPayments] = useState([blankPayment()])
  const [notes, setNotes] = useState('')
  const [supportingFiles, setSupportingFiles] = useState([])
  const [historyFilters, setHistoryFilters] = useState(DEFAULT_HISTORY_FILTERS)
  const [message, setMessage] = useState('')
  const [success, setSuccess] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const loadOptions = useCallback(async () => {
    const response = await api.get('/v1/sales/retail-pos/options', { params: { branch_id: branchId || undefined } })
    setOptions(response.data)
    if (!branchId && response.data.selected_branch?.id) setBranchId(String(response.data.selected_branch.id))
  }, [branchId])

  const loadHistory = useCallback(async (next = DEFAULT_HISTORY_FILTERS) => {
    const response = await api.get('/v1/sales/retail-pos', { params: {
      ...next,
      search: next.search || undefined,
      customer_id: next.customer_id || undefined,
      personnel_id: next.personnel_id || undefined,
      status: next.status === 'ALL' ? undefined : next.status,
      branch_id: branchId || undefined,
    } })
    setHistory(response.data.sales || [])
  }, [branchId])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadOptions(), loadHistory(DEFAULT_HISTORY_FILTERS)])
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Retail POS.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [loadHistory, loadOptions])

  const products = useMemo(() => {
    const term = productSearch.trim().toLowerCase()
    if (!term) return options.products || []
    return (options.products || []).filter((product) => `${product.sku} ${product.barcode || ''} ${product.name} ${product.category || ''} ${product.brand || ''}`.toLowerCase().includes(term))
  }, [options.products, productSearch])

  const gross = useMemo(() => cart.reduce((sum, row) => sum + Number(row.selling_price || 0) * Number(row.quantity || 0), 0), [cart])
  const hasDiscountCode = discountCode.trim() !== ''
  const checkoutTotal = hasDiscountCode && discountPreview ? Number(discountPreview.net_amount || 0) : gross
  const discountAmount = hasDiscountCode && discountPreview ? Number(discountPreview.discount_amount || 0) : 0
  const discountReady = !hasDiscountCode || Boolean(discountPreview)
  const paymentTotal = useMemo(() => payments.reduce((sum, row) => sum + Number(row.amount || 0), 0), [payments])
  const difference = Math.round((checkoutTotal - paymentTotal) * 100) / 100
  const paymentProofRequired = anyPaymentMethodRequiresProof(
    payments.filter((row) => Number(row.amount || 0) > 0.009).map((row) => row.payment_method_reference_id),
    options.payment_methods || [],
  )
  const paymentProofReady = !paymentProofRequired || supportingFiles.length > 0

  function invalidateDiscountPreview() {
    setDiscountPreview(null)
  }

  function handleProductSearchKeyDown(event) {
    if (event.key !== 'Enter') return
    event.preventDefault()
    const term = productSearch.trim().toLowerCase()
    if (!term) return
    const exact = (options.products || []).find((product) =>
      String(product.sku || '').toLowerCase() === term || String(product.barcode || '').toLowerCase() === term
    )
    const product = exact || (products.length === 1 ? products[0] : null)
    if (!product) return
    addProduct(product)
    setProductSearch('')
  }

  function addProduct(product) {
    if (Number(product.available || 0) <= 0) return
    invalidateDiscountPreview()
    setCart((current) => {
      const existing = current.find((row) => row.retail_product_id === product.id)
      if (existing) {
        return current.map((row) => row.retail_product_id === product.id
          ? { ...row, quantity: Math.min(Number(product.available), Number(row.quantity) + 1) }
          : row)
      }
      return [...current, {
        retail_product_id: product.id,
        sku: product.sku,
        name: product.name,
        selling_price: Number(product.selling_price || 0),
        available: Number(product.available || 0),
        quantity: 1,
        assigned_personnel_id: '',
      }]
    })
  }

  function updateCart(index, patch) {
    invalidateDiscountPreview()
    setCart((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row))
  }

  function removeCartLine(index) {
    invalidateDiscountPreview()
    setCart((current) => current.filter((_, rowIndex) => rowIndex !== index))
  }

  function addPaymentRow() {
    setPayments((current) => [...current, blankPayment()])
  }

  function updatePayment(index, patch) {
    setPayments((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row))
  }

  async function applyDiscountPreview() {
    setMessage('')
    setSuccess('')
    if (!cart.length) {
      setMessage('Add at least one retail product before applying a promo code.')
      return
    }
    if (!discountCode.trim()) {
      setDiscountPreview(null)
      return
    }

    setPreviewing(true)
    try {
      const response = await api.post('/v1/sales/retail-pos/preview', {
        branch_id: branchId ? Number(branchId) : null,
        customer_id: customerId ? Number(customerId) : null,
        discount_codes: promotionCodes(discountCode),
        items: cart.map((row) => ({
          retail_product_id: row.retail_product_id,
          quantity: Number(row.quantity),
          assigned_personnel_id: row.assigned_personnel_id ? Number(row.assigned_personnel_id) : null,
        })),
      })
      setDiscountPreview(response.data.preview || null)
    } catch (error) {
      setDiscountPreview(null)
      setMessage(getApiErrorMessage(error, 'Unable to apply the promo code.'))
    } finally {
      setPreviewing(false)
    }
  }

  async function finalize() {
    setSaving(true)
    setMessage('')
    setSuccess('')
    try {
      if (!cart.length) throw new Error('Add at least one retail product.')
      if (cart.some((row) => !row.assigned_personnel_id)) throw new Error('Assigned Staff is required for every retail item.')
      if (hasDiscountCode && !discountPreview) throw new Error('Apply the promo code first so the discounted checkout total can be validated.')
      if (Math.abs(difference) > 0.009) throw new Error('Split Payment Total must equal the Total Checkout Amount.')
      if (checkoutTotal > 0.009 && payments.some((row) => Number(row.amount || 0) > 0 && !row.payment_method_reference_id)) {
        throw new Error('Select a Payment Method for every split payment amount.')
      }
      if (paymentProofRequired && !supportingFiles.length) {
        throw new Error('Payment proof is required for non-cash Retail POS payments such as GCash or bank transfer.')
      }

      const attachmentTokens = await uploadSupportingDocuments(supportingFiles, 'sales', 'CHECKOUT_PAYMENT_PROOF')

      const payload = {
        attachment_tokens: attachmentTokens,
        branch_id: branchId ? Number(branchId) : null,
        customer_id: customerId ? Number(customerId) : null,
        discount_codes: promotionCodes(discountCode),
        notes: notes.trim() || null,
        idempotency_key: newKey(),
        items: cart.map((row) => ({
          retail_product_id: row.retail_product_id,
          quantity: Number(row.quantity),
          assigned_personnel_id: Number(row.assigned_personnel_id),
        })),
        payments: payments.filter((row) => Number(row.amount || 0) > 0).map((row) => ({
          payment_method_reference_id: Number(row.payment_method_reference_id),
          amount: Number(row.amount),
          reference_no: row.reference_no || null,
        })),
      }

      const response = await api.post('/v1/sales/retail-pos', payload)
      setSuccess(`Retail sale ${response.data.sale?.code || ''} finalized.`)
      setCart([])
      setCustomerId('')
      setDiscountCode('')
      setDiscountPreview(null)
      setNotes('')
      setSupportingFiles([])
      setPayments([blankPayment()])
      await Promise.all([loadOptions(), loadHistory()])
    } catch (error) {
      setMessage(getApiErrorMessage(error, error?.message || 'Unable to finalize retail sale.'))
    } finally {
      setSaving(false)
    }
  }

  async function applyHistoryFilters(event) {
    event?.preventDefault()
    setLoading(true); setMessage('')
    try { await loadHistory(historyFilters) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load Retail POS history.')) }
    finally { setLoading(false) }
  }

  async function resetHistoryFilters() {
    setHistoryFilters(DEFAULT_HISTORY_FILTERS)
    setLoading(true); setMessage('')
    try { await loadHistory(DEFAULT_HISTORY_FILTERS) }
    catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load Retail POS history.')) }
    finally { setLoading(false) }
  }

  const canFinalize = hasPermission('retail-pos', 'CREATE')
    && cart.length > 0
    && cart.every((row) => row.assigned_personnel_id)
    && discountReady
    && Math.abs(difference) <= 0.009
    && paymentProofReady
    && (checkoutTotal <= 0.009 || payments.some((row) => Number(row.amount || 0) > 0))

  return (
    <div className="page-stack retail-pos-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Retail POS <PageRefreshButton label="Refresh Retail POS" disabled={loading || saving} onRefresh={() => Promise.all([loadOptions(), loadHistory()])} /></h1>
          <p className="page-subtitle">Standalone retail checkout with SKU stock control, assigned staff commission, and split payments.</p>
        </div>
      </section>

      <div className="booking-tabs">
        <button type="button" className={tab === 'sale' ? 'active' : ''} onClick={() => setTab('sale')}>New Sale</button>
        <button type="button" className={tab === 'history' ? 'active' : ''} onClick={() => setTab('history')}>Retail Sales History</button>
      </div>

      {message ? <div className="form-alert error">{message}</div> : null}
      {success ? <div className="form-alert success">{success}</div> : null}

      {tab === 'sale' ? <>
        <section className="content-card retail-pos-context">
          <div className="booking-filter-panel retail-pos-context-grid">
            <label className="booking-filter-field">Branch
              <SearchableSelect value={branchId} onChange={(e) => { setBranchId(e.target.value); setCart([]); setDiscountPreview(null) }}>
                {(options.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
              </SearchableSelect>
            </label>
            <label className="booking-filter-field retail-pos-customer">Customer (Optional)
              <SearchableSelect value={customerId} onChange={(e) => { setCustomerId(e.target.value); setDiscountPreview(null) }}>
                <option value="">Walk-in Customer</option>
                {(options.customers || []).map((customer) => <option key={customer.id} value={customer.id}>{customer.code} · {customer.name}</option>)}
              </SearchableSelect>
            </label>
            <label className="booking-filter-field retail-pos-product-search">Product / SKU / Barcode
              <input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} onKeyDown={handleProductSearchKeyDown} placeholder="Search or scan product, SKU, barcode" autoFocus />
            </label>
          </div>
        </section>

        <div className="retail-pos-layout">
          <section className="content-card retail-pos-products">
            <div className="card-heading"><div><h2>Products</h2><p>{products.length} available SKU{products.length === 1 ? '' : 's'}</p></div></div>
            <div className="retail-product-grid">
              {products.map((product) => <button key={product.id} type="button" className="retail-product-tile" disabled={Number(product.available || 0) <= 0} onClick={() => addProduct(product)}>
                <span className="retail-product-sku">{product.sku}</span>
                <strong>{product.name}</strong>
                <span>{product.category || product.brand || 'Retail Product'}</span>
                <div><b>{formatPeso(product.selling_price)}</b><small>{product.available} available</small></div>
              </button>)}
              {!products.length ? <div className="empty-state">No matching retail products.</div> : null}
            </div>
          </section>

          <section className="content-card retail-pos-cart">
            <div className="card-heading"><div><h2>Cart</h2><p>Assigned staff is required per product line.</p></div><strong>{formatPeso(gross)}</strong></div>
            <div className="retail-cart-lines">
              {cart.map((row, index) => <div className="retail-cart-line" key={row.retail_product_id}>
                <div className="retail-cart-line-title">
                  <div><strong>{row.name}</strong><span>{row.sku} · {formatPeso(row.selling_price)}</span></div>
                  <button type="button" className="icon-button" onClick={() => removeCartLine(index)}>×</button>
                </div>
                <div className="retail-cart-controls">
                  <label>Qty<input type="number" min="1" max={row.available} value={row.quantity} onChange={(e) => updateCart(index, { quantity: Math.max(1, Math.min(row.available, Number(e.target.value || 1))) })} /></label>
                  <label>Assigned Staff
                    <SearchableSelect value={row.assigned_personnel_id} onChange={(e) => updateCart(index, { assigned_personnel_id: e.target.value })}>
                      <option value="">Select staff</option>
                      {(options.personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </SearchableSelect>
                  </label>
                  <strong>{formatPeso(row.selling_price * row.quantity)}</strong>
                </div>
              </div>)}
              {!cart.length ? <div className="empty-state compact-empty">Add products from the catalog.</div> : null}
            </div>

            <div className="retail-pos-checkout-block">
              <div className="retail-promo-row">
                <label className="form-field">Promotion Codes
                  <input value={discountCode} onChange={(e) => { setDiscountCode(e.target.value.toUpperCase()); setDiscountPreview(null) }} placeholder="Optional, e.g. VIP10, BDAY5" />
                </label>
                <button type="button" className="secondary-button compact-button" disabled={!discountCode.trim() || previewing || !cart.length} onClick={applyDiscountPreview}>
                  {previewing ? 'Applying...' : 'Apply Promo'}
                </button>
              </div>
              {hasDiscountCode && !discountPreview ? <p className="field-help retail-promo-help">Apply the promotion codes to calculate the stacked checkout amount. Codes are applied from left to right.</p> : null}
              {discountPreview ? <div className="retail-promo-applied"><span>{(discountPreview.promotion_codes || discountPreview.discount_codes || [discountPreview.discount_code]).filter(Boolean).join(' + ')} applied</span><strong>-{formatPeso(discountAmount)}</strong></div> : null}

              <label className="form-field">Notes<input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional receipt note" /></label>

              <div className="retail-payment-heading"><strong>Payment Methods</strong><button type="button" className="secondary-button compact-button" onClick={addPaymentRow}>+ Split Payment</button></div>
              <div className="retail-payment-rows">
                {payments.map((row, index) => <div className="retail-payment-row" key={index}>
                  <SearchableSelect value={row.payment_method_reference_id} onChange={(e) => updatePayment(index, { payment_method_reference_id: e.target.value })}>
                    <option value="">Payment Method</option>
                    {(options.payment_methods || []).map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                  </SearchableSelect>
                  <input type="number" min="0" step="0.01" value={row.amount} onChange={(e) => updatePayment(index, { amount: e.target.value })} placeholder="Amount" />
                  <input value={row.reference_no} onChange={(e) => updatePayment(index, { reference_no: e.target.value })} placeholder="Reference No. (optional)" />
                  {payments.length > 1 ? <button type="button" className="icon-button" onClick={() => setPayments((current) => current.filter((_, i) => i !== index))}>×</button> : null}
                </div>)}
              </div>

              <SupportingDocumentField
                required={paymentProofRequired}
                files={supportingFiles}
                onChange={setSupportingFiles}
                label="Payment Proof / Supporting File"
                hint={paymentProofRequired
                  ? 'Required because at least one payment uses a non-cash method such as GCash, bank transfer, e-wallet or card. Cash-only payment is exempt.'
                  : 'Cash-only payment does not require payment proof.'}
              />
              {paymentProofRequired && !paymentProofReady ? <p className="field-help retail-payment-warning">Upload payment proof before finalizing this non-cash Retail POS sale.</p> : null}

              <div className="retail-pos-totals">
                <div><span>Gross Retail Sale</span><strong>{formatPeso(gross)}</strong></div>
                {discountAmount > 0.009 ? <div><span>Discount</span><strong>-{formatPeso(discountAmount)}</strong></div> : null}
                <div><span>Total Checkout Amount</span><strong>{formatPeso(checkoutTotal)}</strong></div>
                <div><span>Split Payment Total</span><strong>{formatPeso(paymentTotal)}</strong></div>
                <div className={discountReady && Math.abs(difference) <= 0.009 ? 'balanced' : 'unbalanced'}><span>Difference</span><strong>{formatPeso(difference)}</strong></div>
              </div>

              <button className="primary-button retail-finalize-button" type="button" disabled={!canFinalize || saving || previewing} onClick={finalize}>{saving ? 'Finalizing...' : 'Finalize Retail Sale'}</button>
              {discountReady && Math.abs(difference) > 0.009 ? <p className="field-help retail-payment-warning">Split Payment Total must equal the Total Checkout Amount before finalization.</p> : null}
            </div>
          </section>
        </div>
      </> : <section className="content-card">
        <form className="retail-history-filter-grid" onSubmit={applyHistoryFilters}>
          <label className="form-field"><span>Search</span><input value={historyFilters.search} onChange={(e) => setHistoryFilters((c) => ({ ...c, search: e.target.value }))} placeholder="Receipt, customer, SKU, product" /></label>
          <label className="form-field"><span>From</span><input type="date" value={historyFilters.from} onChange={(e) => setHistoryFilters((c) => ({ ...c, from: e.target.value }))} /></label>
          <label className="form-field"><span>To</span><input type="date" value={historyFilters.to} onChange={(e) => setHistoryFilters((c) => ({ ...c, to: e.target.value }))} /></label>
          <label className="form-field"><span>Customer</span><SearchableSelect value={historyFilters.customer_id} onChange={(e) => setHistoryFilters((c) => ({ ...c, customer_id: e.target.value }))}><option value="">All Customers</option>{(options.customers || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Assigned Staff</span><SearchableSelect value={historyFilters.personnel_id} onChange={(e) => setHistoryFilters((c) => ({ ...c, personnel_id: e.target.value }))}><option value="">All Staff</option>{(options.personnel || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Status</span><SearchableSelect value={historyFilters.status} onChange={(e) => setHistoryFilters((c) => ({ ...c, status: e.target.value }))}><option value="ALL">All Statuses</option><option value="PAID">Paid</option><option value="REFUNDED">Refunded</option><option value="CANCELLED">Cancelled</option></SearchableSelect></label>
          <div className="filter-actions-inline"><button className="primary-button" type="submit">Apply</button><button className="secondary-button" type="button" onClick={resetHistoryFilters}>Reset</button></div>
        </form>
        <div className="table-wrap"><table className="data-table"><thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Items</th><th>Qty</th><th>Assigned Staff</th><th>Gross</th><th>Discount</th><th>Net</th><th>Payment</th><th>Status</th></tr></thead><tbody>
          {history.map((sale) => <tr key={sale.id}>
            <td><strong>{sale.code}</strong></td><td>{formatDisplayDate(sale.sale_date)}</td><td>{sale.customer?.name || 'Walk-in Customer'} {sale.customer ? <CustomerSegmentTag customerClass={sale.customer?.customer_class} customerType={sale.customer?.customer_type || sale.customer?.segment} typeColor={sale.customer?.customer_type_color} /> : null}</td>
            <td><div className="retail-history-lines">{sale.items.map((item) => <div className="retail-history-line" key={item.id}><strong>{item.name || 'Retail Item'}</strong><span className="table-subline">{item.sku || '—'}</span></div>)}</div></td>
            <td><div className="retail-history-lines retail-history-quantity">{sale.items.map((item) => <div className="retail-history-line" key={item.id}><strong>{item.quantity}</strong></div>)}</div></td>
            <td>{[...new Set(sale.items.map((item) => item.assigned_staff?.name).filter(Boolean))].join(', ') || '—'}</td>
            <td>{formatPeso(sale.gross_amount)}</td><td>{formatPeso(sale.discount_amount)}</td><td><strong>{formatPeso(sale.net_amount)}</strong></td>
            <td>{sale.payments.length ? <div className="retail-history-lines">{sale.payments.map((payment) => <div className="retail-history-line" key={payment.id}><strong>{formatPeso(payment.amount)}</strong><span className="table-subline">{payment.method || '—'}</span></div>)}</div> : '—'}</td><td><span className={`status-pill ${['REVERSED','CANCELLED','REFUNDED'].includes(sale.status) ? 'danger' : ['PAID','COMPLETED'].includes(sale.status) ? 'success' : sale.status === 'ON_ACCOUNT' ? 'warning' : 'neutral'}`}>{sale.status}</span></td>
          </tr>)}
          {!history.length ? <tr><td colSpan="11"><div className="empty-state compact-empty">No Retail POS sales found.</div></td></tr> : null}
        </tbody></table></div>
      </section>}
    </div>
  )
}
