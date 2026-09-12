import { formatPeso } from '../../utils/formatters'

export default function PaymentIndicator({ advanceAmount = 0 }) {
  const amount = Number(advanceAmount || 0)

  if (amount <= 0) {
    return <span className="payment-unpaid">Unpaid</span>
  }

  return (
    <span className="payment-indicator">
      <strong>Advance</strong>
      <span className="table-subline">{formatPeso(amount)}</span>
    </span>
  )
}
