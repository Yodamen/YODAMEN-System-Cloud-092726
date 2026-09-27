function normalizeHex(value) {
  const color = String(value || '').trim().toUpperCase()
  return /^#[0-9A-F]{6}$/.test(color) ? color : '#8A8A8A'
}

function contrastColor(hex) {
  const value = normalizeHex(hex).slice(1)
  const r = parseInt(value.slice(0, 2), 16)
  const g = parseInt(value.slice(2, 4), 16)
  const b = parseInt(value.slice(4, 6), 16)
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
  return luminance > 0.62 ? '#171717' : '#FFFFFF'
}

export default function CustomerSegmentTag({ customerClass, customerType, typeColor, segment }) {
  const label = String(customerClass || segment || '').trim()
  if (!label) return null

  const color = normalizeHex(typeColor)
  const typeLabel = String(customerType || segment || '').trim()

  return (
    <span
      className="customer-segment-tag"
      style={{ backgroundColor: color, borderColor: color, color: contrastColor(color) }}
      title={typeLabel ? `Customer Type: ${typeLabel}` : undefined}
      aria-label={typeLabel ? `${label}, Customer Type ${typeLabel}` : label}
    >
      {label}
    </span>
  )
}
