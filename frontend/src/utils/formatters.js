const monthNames = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.']

export function formatDisplayDate(value) {
  if (!value) return '—'

  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!match) return value

  const [, year, month, day] = match
  const monthLabel = monthNames[Number(month) - 1]
  if (!monthLabel) return value

  return `${monthLabel} ${Number(day)}, ${year}`
}


export function formatDisplayTime(value) {
  if (!value) return '—'

  const match = String(value).match(/^(\d{1,2}):(\d{2})/)
  if (!match) return value

  let hour = Number(match[1])
  const minute = match[2]
  const suffix = hour >= 12 ? 'PM' : 'AM'
  hour = hour % 12 || 12

  return `${hour}:${minute} ${suffix}`
}


export function formatPeso(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
