import SearchableSelect from '../common/SearchableSelect'
import { useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'
import SupportingDocumentField from '../common/SupportingDocumentField'
import { anyPaymentMethodRequiresProof, uploadSupportingDocuments } from '../../utils/supportingDocuments'

function newIdempotencyKey(prefix) {
  const suffix = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
  return `${prefix}-${suffix}`
}

function amount(value) {
  const parsed = Number(value || 0)
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}

function sumMap(map) {
  return Object.values(map).reduce((total, value) => total + amount(value), 0)
}

function chargeableItems(items) {
  return (items || []).filter((item) => item.chargeable)
}

function emptyAllocationMap(items) {
  return Object.fromEntries((items || []).map((item) => [item.id, '']))
}

function transactionTypeLabel(item) {
  const type = String(item?.item_type || '').toUpperCase()
  if (type === 'SERVICE_CREDIT_USE') return 'Service Credit'
  if (type === 'PACKAGE') return 'Package'
  if (type === 'PRODUCT') return 'Retail Purchase'
  return 'Service'
}

function normalizeCode(value) {
  return String(value || '').trim().toUpperCase()
}

function promotionCodes(value) {
  return [...new Set(String(value || '').toUpperCase().split(/[\s,;+]+/).map((code) => code.trim()).filter(Boolean))]
}

function promotionKey(value) {
  return promotionCodes(value).join(',')
}

export default function CheckoutModal({ detail, allocation, onClose, onFinalized }) {
  const [discountCode, setDiscountCode] = useState('')
  const [appliedDiscountCode, setAppliedDiscountCode] = useState('')
  const [discountAllocation, setDiscountAllocation] = useState(null)
  const activeAllocation = discountAllocation || allocation || {}
  const items = activeAllocation.items || []

  const [collectedAllocations, setCollectedAllocations] = useState(() => emptyAllocationMap(allocation?.items || []))
  const [currentAllocations, setCurrentAllocations] = useState(() => emptyAllocationMap(allocation?.items || []))
  const [paymentSplits, setPaymentSplits] = useState(() => ([{ key: 'payment-1', payment_method_reference_id: '', amount: '', reference_no: '' }]))
  const [tipAmount, setTipAmount] = useState('')
  const [tipPersonnelId, setTipPersonnelId] = useState('')
  const [tipPaymentMethodId, setTipPaymentMethodId] = useState('')
  const [paymentSplitTouched, setPaymentSplitTouched] = useState(false)
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [applyingDiscount, setApplyingDiscount] = useState(false)
  const [supportingFiles, setSupportingFiles] = useState([])

  const requiredAdvance = Number(activeAllocation.advance_required_allocation || 0)
  const requiredInterimConfirmation = Number(activeAllocation.booking_interim_confirmation_required || 0)
  const requiredExistingCollected = requiredAdvance + requiredInterimConfirmation
  const discountSummary = activeAllocation.discount || {
    code: null,
    gross_amount: Number(detail?.gross_amount || 0),
    discount_amount: 0,
    net_amount: Number(detail?.gross_amount || 0),
  }

  const bookingAdvance = Number(detail?.booking_advance_payment_total || 0)
  const bookingInterim = Number(detail?.booking_interim_payment_total || 0)
  const bookingCheckout = Number(detail?.booking_checkout_payment_total || 0)
  const bookingCollectionTotal = Number(
    detail?.booking_collection_total || (bookingAdvance + bookingInterim + bookingCheckout),
  )
  const hasBookingPaymentContext = bookingCollectionTotal > 0.009

  const advanceAllocations = useMemo(
    () => Object.fromEntries(items.map((item) => {
      const entered = amount(collectedAllocations[item.id])
      const alreadyAppliedInterim = Number(item.booking_interim_confirmation_amount || 0)
      return [item.id, Math.max(0, entered - alreadyAppliedInterim)]
    })),
    [items, collectedAllocations],
  )
  const advanceSum = useMemo(() => sumMap(advanceAllocations), [advanceAllocations])
  const collectedSum = useMemo(() => sumMap(collectedAllocations), [collectedAllocations])
  const bookingInterimConfirmationReady = useMemo(
    () => items.every((item) => (
      amount(collectedAllocations[item.id]) + 0.009 >= Number(item.booking_interim_confirmation_amount || 0)
    )),
    [items, collectedAllocations],
  )
  const currentSalePaymentNow = useMemo(
    () => items
      .filter((item) => item.chargeable)
      .reduce((total, item) => total + amount(currentAllocations[item.id]), 0),
    [items, currentAllocations],
  )
  const interimPaymentNow = useMemo(
    () => items
      .filter((item) => !item.chargeable && item.service_credit_interim_eligible)
      .reduce((total, item) => total + amount(currentAllocations[item.id]), 0),
    [items, currentAllocations],
  )
  const paymentNow = currentSalePaymentNow + interimPaymentNow
  const projectedBalance = Math.max(0, Number(activeAllocation.current_chargeable_balance || 0) - advanceSum - currentSalePaymentNow)
  const balanceBeforeCheckout = Math.max(0, Number(activeAllocation.current_chargeable_balance || 0) - requiredAdvance)

  const projectedByItem = useMemo(() => items.map((item) => {
    const fixedApplied = Number(item.applied_amount || 0)
    const advance = amount(advanceAllocations[item.id])
    const current = item.chargeable ? amount(currentAllocations[item.id]) : 0
    const payable = Number(item.net_line_total ?? item.line_total ?? 0)
    const remaining = Math.max(0, payable - fixedApplied - advance - current)
    return { ...item, projected_balance: remaining }
  }), [items, advanceAllocations, currentAllocations])

  const requiredFullSettlementBalance = useMemo(
    () => projectedByItem
      .filter((item) => item.chargeable && !item.installment_eligible)
      .reduce((total, item) => total + Number(item.projected_balance || 0), 0),
    [projectedByItem],
  )

  const installmentEligibleBalance = useMemo(
    () => projectedByItem
      .filter((item) => item.chargeable && item.installment_eligible)
      .reduce((total, item) => total + Number(item.projected_balance || 0), 0),
    [projectedByItem],
  )

  const serviceCreditPackageProjection = useMemo(() => {
    const byTarget = new Map()

    items
      .filter((item) => !item.chargeable && item.service_credit_target_sale_item_id)
      .forEach((item) => {
        const targetKey = String(item.service_credit_target_sale_item_id || item.id)
        const current = byTarget.get(targetKey) || {
          balance: Number(item.service_credit_package_balance || 0),
          applied: 0,
          confirmedBookingInterim: 0,
        }
        current.applied += amount(advanceAllocations[item.id]) + amount(currentAllocations[item.id])
        current.confirmedBookingInterim += Number(item.booking_interim_confirmation_amount || 0)
        byTarget.set(targetKey, current)
      })

    const rows = Array.from(byTarget.values())
    return {
      hadPayment: rows.some((row) => row.applied > 0.009 || row.confirmedBookingInterim > 0.009),
      remaining: rows.reduce((total, row) => total + Math.max(0, row.balance - row.applied), 0),
    }
  }, [items, advanceAllocations, currentAllocations])

  const collectedTotalReady = Math.abs(collectedSum - requiredExistingCollected) <= 0.009
  const advanceReady = Math.abs(advanceSum - requiredAdvance) <= 0.009
  const existingCollectedReady = collectedTotalReady && advanceReady && bookingInterimConfirmationReady
  const tipValue = amount(tipAmount)
  const totalReceivedAtCheckout = paymentNow + tipValue
  const selectedPaymentMethodIds = useMemo(
    () => new Set(paymentSplits.map((row) => Number(row.payment_method_reference_id || 0)).filter(Boolean)),
    [paymentSplits],
  )
  const eligibleTipPaymentMethods = useMemo(
    () => (activeAllocation.payment_methods || []).filter((method) => method.supports_tip && selectedPaymentMethodIds.has(Number(method.id))),
    [activeAllocation.payment_methods, selectedPaymentMethodIds],
  )
  const paymentSplitTotal = useMemo(
    () => paymentSplits.reduce((total, row) => total + amount(row.amount), 0),
    [paymentSplits],
  )
  const paymentSplitDifference = Math.round((totalReceivedAtCheckout - paymentSplitTotal) * 100) / 100
  const paymentProofRequired = anyPaymentMethodRequiresProof(
    paymentSplits.filter((row) => amount(row.amount) > 0.009).map((row) => row.payment_method_reference_id),
    activeAllocation.payment_methods || [],
  )
  const paymentProofReady = !paymentProofRequired || supportingFiles.length > 0
  const paymentSplitReady = totalReceivedAtCheckout <= 0.009 || (
    paymentSplits.length > 0
    && paymentSplits.every((row) => Number(row.payment_method_reference_id || 0) > 0 && amount(row.amount) > 0)
    && Math.abs(paymentSplitDifference) <= 0.009
  )
  const tipReady = tipValue <= 0.009 || (
    Number(tipPersonnelId || 0) > 0
    && Number(tipPaymentMethodId || 0) > 0
    && eligibleTipPaymentMethods.some((method) => Number(method.id) === Number(tipPaymentMethodId))
  )
  const fullSettlementReady = requiredFullSettlementBalance <= 0.009
  const balanceReady = projectedBalance <= 0.009 || (fullSettlementReady && installmentEligibleBalance > 0.009)
  const discountReady = promotionKey(discountCode) === appliedDiscountCode
  const canFinalize = existingCollectedReady && paymentSplitReady && paymentProofReady && tipReady && fullSettlementReady && balanceReady && discountReady && !saving && !applyingDiscount
  useEffect(() => {
    if (paymentSplits.length !== 1 || paymentSplitTouched) return
    setPaymentSplits((rows) => rows.map((row, index) => (
      index === 0 ? { ...row, amount: totalReceivedAtCheckout > 0.009 ? totalReceivedAtCheckout.toFixed(2) : '' } : row
    )))
  }, [totalReceivedAtCheckout, paymentSplitTouched, paymentSplits.length])

  useEffect(() => {
    if (tipValue <= 0.009) {
      if (tipPaymentMethodId) setTipPaymentMethodId('')
      return
    }
    if (eligibleTipPaymentMethods.length === 1 && !tipPaymentMethodId) {
      setTipPaymentMethodId(String(eligibleTipPaymentMethods[0].id))
      return
    }
    if (tipPaymentMethodId && !eligibleTipPaymentMethods.some((method) => String(method.id) === String(tipPaymentMethodId))) {
      setTipPaymentMethodId('')
    }
  }, [tipValue, eligibleTipPaymentMethods, tipPaymentMethodId])

  function updatePaymentSplit(key, field, value) {
    setPaymentSplitTouched(true)
    setPaymentSplits((rows) => rows.map((row) => (row.key === key ? { ...row, [field]: value } : row)))
  }

  function addPaymentSplit() {
    setPaymentSplitTouched(true)
    setPaymentSplits((rows) => [
      ...rows,
      { key: `payment-${Date.now()}-${rows.length + 1}`, payment_method_reference_id: '', amount: '', reference_no: '' },
    ])
  }

  function removePaymentSplit(key) {
    setPaymentSplitTouched(true)
    setPaymentSplits((rows) => rows.length <= 1 ? rows : rows.filter((row) => row.key !== key))
  }

  const chargeableCount = chargeableItems(items).length
  const projectedStatus = !fullSettlementReady
    ? 'Full payment required'
    : projectedBalance > 0.009 || (serviceCreditPackageProjection.hadPayment && serviceCreditPackageProjection.remaining > 0.009)
      ? 'On Account'
      : chargeableCount === 0
        ? serviceCreditPackageProjection.hadPayment ? 'Paid' : 'Service Credit'
        : 'Paid'

  function setLineAllocation(setter, itemId, value) {
    setter((current) => ({ ...current, [itemId]: value }))
  }

  function rowsFrom(map, sourceItems) {
    return sourceItems.map((item) => ({ sale_item_id: item.id, amount: amount(map[item.id]) }))
  }

  function resetAllocations(sourceItems) {
    setCollectedAllocations(emptyAllocationMap(sourceItems))
    setCurrentAllocations(emptyAllocationMap(sourceItems))
  }


  function updateDiscountCode(value) {
    const next = String(value || '').toUpperCase()
    if (promotionKey(next) !== appliedDiscountCode) {
      setAppliedDiscountCode('')
      setDiscountAllocation(null)
      resetAllocations(allocation?.items || [])
    }
    setDiscountCode(next)
    setMessage('')
  }

  async function applyDiscount() {
    const codes = promotionCodes(discountCode)
    const code = codes.join(',')
    setMessage('')

    if (!code) {
      setAppliedDiscountCode('')
      setDiscountAllocation(null)
      resetAllocations(allocation?.items || [])
      return
    }

    setApplyingDiscount(true)
    try {
      const response = await api.post(`/v1/sales/transactions/${detail.id}/discount-preview`, {
        discount_codes: codes,
      })
      const nextAllocation = response.data.allocation || null
      setDiscountCode(codes.join(', '))
      setAppliedDiscountCode(code)
      setDiscountAllocation(nextAllocation)
      resetAllocations(nextAllocation?.items || allocation?.items || [])
    } catch (error) {
      setAppliedDiscountCode('')
      setDiscountAllocation(null)
      resetAllocations(allocation?.items || [])
      setMessage(getApiErrorMessage(error, 'Unable to apply discount code.'))
    } finally {
      setApplyingDiscount(false)
    }
  }

  function clearDiscount() {
    setDiscountCode('')
    setAppliedDiscountCode('')
    setDiscountAllocation(null)
    resetAllocations(allocation?.items || [])
    setMessage('')
  }

  async function finalize() {
    setSaving(true)
    setMessage('')

    try {
      const attachmentTokens = await uploadSupportingDocuments(supportingFiles, 'sales', 'CHECKOUT_PAYMENT_PROOF')
      const response = await api.post(`/v1/sales/transactions/${detail.id}/finalize-checkout`, {
        idempotency_key: newIdempotencyKey('checkout'),
        discount_codes: appliedDiscountCode ? appliedDiscountCode.split(',').filter(Boolean) : [],
        collected_allocations: rowsFrom(collectedAllocations, items),
        advance_allocations: rowsFrom(advanceAllocations, items),
        amount_collected_now: paymentNow,
        tip_amount: tipValue,
        tip_personnel_id: tipValue > 0.009 ? Number(tipPersonnelId || 0) : null,
        tip_payment_method_reference_id: tipValue > 0.009 ? Number(tipPaymentMethodId || 0) : null,
        payment_splits: paymentNow > 0.009
          ? paymentSplits.map((row) => ({
              payment_method_reference_id: Number(row.payment_method_reference_id || 0),
              amount: amount(row.amount),
              reference_no: row.reference_no || null,
            }))
          : [],
        checkout_allocations: rowsFrom(currentAllocations, items),
        allow_remaining_balance: projectedBalance > 0.009,
        attachment_tokens: attachmentTokens,
      })
      onFinalized(response.data.sale)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to finalize checkout.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      title={detail.customer?.name || 'Checkout'}
      subtitle={`${detail.booking?.code || 'Booking'} · ${formatDisplayDate(detail.sale_date)}`}
      onClose={onClose}
      wide
    >
      <div className="checkout-simple-summary checkout-discount-summary">
        <div>
          <span>Gross</span>
          <strong>{formatPeso(discountSummary.gross_amount)}</strong>
        </div>
        <div>
          <span>Discount</span>
          <strong>{formatPeso(discountSummary.discount_amount)}</strong>
        </div>
        <div>
          <span>Net Due</span>
          <strong>{formatPeso(discountSummary.net_amount)}</strong>
        </div>
        <div>
          <span>{hasBookingPaymentContext ? 'Collected' : 'Paid'}</span>
          <strong>{formatPeso(hasBookingPaymentContext ? bookingCollectionTotal : detail.payments_received)}</strong>
        </div>
        <div>
          <span>Balance</span>
          <strong>{formatPeso(balanceBeforeCheckout)}</strong>
        </div>
      </div>

      <section className="checkout-discount-bar">
        <label>
          Promotion Codes
          <div className="checkout-discount-code-control">
            <input
              value={discountCode}
              onChange={(event) => updateDiscountCode(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  applyDiscount()
                }
              }}
              placeholder="Optional, e.g. VIP10, BDAY5"
              maxLength={320}
            />
            <button className="secondary-button compact-button" type="button" onClick={applyDiscount} disabled={applyingDiscount || saving}>
              {applyingDiscount ? 'Applying...' : 'Apply'}
            </button>
            {appliedDiscountCode ? <button className="text-button" type="button" onClick={clearDiscount} disabled={saving}>Clear</button> : null}
          </div>
        </label>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}
      {!discountReady ? <div className="checkout-inline-validation">Apply the entered Promotion Codes before finalizing.</div> : null}

      <section className="checkout-simple-section">
        <div className="table-wrap">
          <table className="data-table checkout-simple-table">
            <thead>
              <tr>
                <th>Service</th>
                <th className="money-column">Net Amount</th>
                <th className="money-column">Collected</th>
                <th className="money-column">Checkout Payment</th>
                <th className="money-column">Balance After</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const fixedApplied = Number(item.applied_amount || 0)
                const advance = amount(advanceAllocations[item.id])
                const current = amount(currentAllocations[item.id])
                const paidBefore = fixedApplied + advance
                const grossLine = Number(item.gross_line_total ?? item.line_total ?? 0)
                const discountLine = Number(item.discount_amount || 0)
                const netLine = Number(item.net_line_total ?? item.line_total ?? 0)
                const remaining = item.chargeable
                  ? Math.max(0, netLine - paidBefore - current)
                  : 0
                return (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.item_name}</strong>
                      {item.assigned_personnel_name ? <span className="table-subline">{item.assigned_personnel_name}</span> : null}
                      <span className="table-subline">{transactionTypeLabel(item)}</span>
                      {item.service_credit_package_booking_code ? (
                        <span className="table-subline">Source Booking ID: {item.service_credit_package_booking_code}</span>
                      ) : null}
                    </td>
                    <td className="money-cell checkout-net-amount-cell">
                      <strong>{formatPeso(netLine)}</strong>
                      {discountLine > 0.009 ? <span className="table-subline">{formatPeso(grossLine)} · −{formatPeso(discountLine)}</span> : null}
                    </td>
                    <td className="checkout-simple-money-cell">
                      {requiredExistingCollected > 0 && (
                        item.chargeable
                        || item.service_credit_interim_eligible
                        || Number(item.booking_interim_confirmation_amount || 0) > 0
                      ) ? (
                        <div className="checkout-paid-before-input">
                          <span>{fixedApplied > 0 ? `${formatPeso(fixedApplied)} +` : ''}</span>
                          <input
                            className="allocation-input"
                            type="number"
                            min="0"
                            step="0.01"
                            value={collectedAllocations[item.id] ?? ''}
                            onChange={(event) => setLineAllocation(setCollectedAllocations, item.id, event.target.value)}
                            placeholder="0.00"
                            aria-label={`Existing Booking collection allocated to ${item.item_name}`}
                          />
                        </div>
                      ) : <span>{formatPeso(fixedApplied)}</span>}
                    </td>
                    <td className="checkout-simple-money-cell">
                      <input
                        className="allocation-input"
                        type="number"
                        min="0"
                        max={!item.chargeable && item.service_credit_interim_eligible ? Number(item.service_credit_package_balance || 0) : undefined}
                        step="0.01"
                        disabled={!item.chargeable && !item.service_credit_interim_eligible}
                        value={currentAllocations[item.id] ?? ''}
                        onChange={(event) => setLineAllocation(setCurrentAllocations, item.id, event.target.value)}
                        placeholder="0.00"
                        aria-label={item.service_credit_interim_eligible ? `Payment applied to package behind ${item.item_name}` : `Payment applied now to ${item.item_name}`}
                      />
                    </td>
                    <td className="money-cell">
                      <strong>{formatPeso(remaining)}</strong>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {!existingCollectedReady ? (
          <div className="checkout-inline-validation">
            Allocate exactly {formatPeso(requiredExistingCollected)} of the existing Booking collection in the Collected column before finalizing.
          </div>
        ) : null}
      </section>

      <section className="checkout-split-payment">
        <div className="checkout-split-payment-heading">
          <div className="checkout-simple-payment-total">
            <span>Total Customer Payment</span>
            <strong>{formatPeso(totalReceivedAtCheckout)}</strong>
            {tipValue > 0.009 ? <small>{formatPeso(paymentNow)} checkout + {formatPeso(tipValue)} tip</small> : null}
          </div>
          {totalReceivedAtCheckout > 0.009 ? (
            <button className="secondary-button compact-button" type="button" onClick={addPaymentSplit} disabled={saving}>+ Split Payment</button>
          ) : null}
        </div>

        {totalReceivedAtCheckout > 0.009 ? (
          <>
            <div className="checkout-split-payment-rows">
              {paymentSplits.map((row, index) => (
                <div className="checkout-split-payment-row" key={row.key}>
                  <label>
                    Payment Method {paymentSplits.length > 1 ? index + 1 : ''}
                    <SearchableSelect
                      value={row.payment_method_reference_id}
                      onChange={(event) => updatePaymentSplit(row.key, 'payment_method_reference_id', event.target.value)}
                    >
                      <option value="">Select method</option>
                      {(activeAllocation.payment_methods || []).map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                    </SearchableSelect>
                  </label>
                  <label>
                    Amount
                    <input
                      type="number"
                      min="0.01"
                      step="0.01"
                      value={row.amount}
                      onChange={(event) => updatePaymentSplit(row.key, 'amount', event.target.value)}
                      placeholder="0.00"
                    />
                  </label>
                  <label>
                    Reference No.
                    <input
                      value={row.reference_no}
                      onChange={(event) => updatePaymentSplit(row.key, 'reference_no', event.target.value)}
                      placeholder="Optional"
                    />
                  </label>
                  {paymentSplits.length > 1 ? (
                    <button className="checkout-split-remove" type="button" onClick={() => removePaymentSplit(row.key)} aria-label={`Remove payment method ${index + 1}`}>×</button>
                  ) : null}
                </div>
              ))}
            </div>
            <div className="checkout-tip-row">
              <label>
                Electronic Tip
                <input type="number" min="0" step="0.01" value={tipAmount} onChange={(event) => setTipAmount(event.target.value)} placeholder="0.00" />
              </label>
              <label>
                Tip For
                <SearchableSelect value={tipPersonnelId} onChange={(event) => setTipPersonnelId(event.target.value)} disabled={tipValue <= 0.009}>
                  <option value="">Select personnel</option>
                  {(activeAllocation.tip_personnel || allocation?.tip_personnel || []).map((personnel) => <option key={personnel.id} value={personnel.id}>{personnel.name}</option>)}
                </SearchableSelect>
              </label>
              <label>
                Tip Received Via
                <SearchableSelect value={tipPaymentMethodId} onChange={(event) => setTipPaymentMethodId(event.target.value)} disabled={tipValue <= 0.009}>
                  <option value="">{eligibleTipPaymentMethods.length ? 'Select bank / e-wallet' : 'Select bank / e-wallet payment first'}</option>
                  {eligibleTipPaymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                </SearchableSelect>
              </label>
            </div>
            {tipValue > 0.009 && !tipReady ? <div className="checkout-inline-validation">Electronic tips are tracked only when received through a selected business bank, e-wallet, or card/POS payment method. Select the personnel and the payment method carrying the tip.</div> : null}
            <div className={`checkout-split-control ${paymentSplitReady ? 'is-balanced' : 'has-difference'}`}>
              <span>Split Total <strong>{formatPeso(paymentSplitTotal)}</strong></span>
              <span>Required <strong>{formatPeso(totalReceivedAtCheckout)}</strong></span>
              <span>Difference <strong>{formatPeso(Math.abs(paymentSplitDifference))}</strong></span>
            </div>
            {!paymentSplitReady ? (
              <div className="checkout-inline-validation checkout-split-validation">
                Split Payment amounts must total exactly {formatPeso(totalReceivedAtCheckout)}, including any electronic tip, and every row must have a Payment Method.
              </div>
            ) : null}
            <SupportingDocumentField
              required={paymentProofRequired}
              files={supportingFiles}
              onChange={setSupportingFiles}
              label="Payment Proof / Supporting File"
              hint={paymentProofRequired
                ? 'Required because at least one payment uses a non-cash method such as GCash, bank transfer, e-wallet or card. Cash-only checkout is exempt.'
                : 'Cash-only checkout does not require payment proof.'}
            />
            {paymentProofRequired && !paymentProofReady ? <div className="checkout-inline-validation">Upload payment proof before finalizing this non-cash checkout.</div> : null}
          </>
        ) : (
          <div className="checkout-zero-payment-note">No Payment Method is required when there is no Checkout Payment or tracked electronic tip.</div>
        )}
      </section>

      {requiredFullSettlementBalance > 0.009 ? (
        <div className="checkout-inline-validation">
          {formatPeso(requiredFullSettlementBalance)} from single-session services or product purchases must be fully paid before checkout can be finalized. Only multi-session packages may carry a balance.
        </div>
      ) : null}

      <div className="checkout-simple-footer">
        <div className="checkout-simple-result">
          <span>Checkout Result</span>
          <strong>{projectedStatus}</strong>
          {projectedStatus === 'On Account' ? (
            <small>{formatPeso(projectedBalance > 0.009 ? projectedBalance : serviceCreditPackageProjection.remaining)} will remain collectible.</small>
          ) : null}
        </div>
        <div className="modal-actions checkout-clean-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button>
          <button className="primary-button" type="button" onClick={finalize} disabled={!canFinalize}>{saving ? 'Finalizing...' : 'Finalize Checkout'}</button>
        </div>
      </div>
    </Modal>
  )
}
