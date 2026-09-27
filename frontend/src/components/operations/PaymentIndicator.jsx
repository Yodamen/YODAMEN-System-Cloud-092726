import { formatPeso } from '../../utils/formatters'

function statusLabel(status) {
  const normalized = String(status || '').toUpperCase()
  if (normalized === 'PAID') return 'Paid'
  if (normalized === 'ON_ACCOUNT') return 'On Account'
  if (normalized === 'SERVICE_CREDIT') return 'Service Credit'
  if (normalized === 'PAID_SERVICE_CREDIT') return 'Paid Service Credit'
  if (normalized === 'PENDING_CHECKOUT') return 'Pending Checkout'
  if (normalized === 'CHECKOUT') return 'Checkout Payment'
  return ''
}

export default function PaymentIndicator({
  paymentStatus = '',
  paymentType = '',
  netAfterDiscountAmount = 0,
  collectedReceivedAmount = null,
  advanceAmount = 0,
  interimAmount = 0,
  checkoutAmount = 0,
}) {
  const status = String(paymentStatus || '').toUpperCase()
  const type = String(paymentType || paymentStatus || '').toUpperCase()
  const netAfterDiscount = Number(netAfterDiscountAmount || 0)
  const hasCollectedReceivedAmount = collectedReceivedAmount !== null && collectedReceivedAmount !== undefined
  const finalizedAmount = hasCollectedReceivedAmount ? Number(collectedReceivedAmount || 0) : netAfterDiscount
  const advance = Number(advanceAmount || 0)
  const interim = Number(interimAmount || 0)
  const checkout = Number(checkoutAmount || 0)
  const finalizedLabel = statusLabel(type)

  if (finalizedLabel) {
    return (
      <span className="payment-indicator payment-indicator-status">
        <strong>{finalizedLabel}</strong>
        {type !== 'SERVICE_CREDIT' || finalizedAmount > 0 ? <span className="table-subline">{formatPeso(finalizedAmount)}</span> : null}
      </span>
    )
  }

  if (advance <= 0 && interim <= 0 && checkout <= 0) {
    return <span className="payment-unpaid">Unpaid</span>
  }

  return (
    <span className="payment-indicator">
      {advance > 0 ? <span><strong>Advance</strong><span className="table-subline">{formatPeso(advance)}</span></span> : null}
      {interim > 0 ? <span><strong>Interim</strong><span className="table-subline">{formatPeso(interim)}</span></span> : null}
      {checkout > 0 ? <span><strong>Checkout Payment</strong><span className="table-subline">{formatPeso(checkout)}</span></span> : null}
    </span>
  )
}
