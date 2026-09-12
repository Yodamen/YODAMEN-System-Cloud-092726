import { useState } from 'react'

export default function PageRefreshButton({ label = 'Refresh', disabled = false, onRefresh }) {
  const [refreshing, setRefreshing] = useState(false)

  async function handleRefresh() {
    if (disabled || refreshing || typeof onRefresh !== 'function') return

    setRefreshing(true)
    try {
      await onRefresh()
    } finally {
      setRefreshing(false)
    }
  }

  return (
    <button
      className={`live-refresh-button${refreshing ? ' refreshing' : ''}`}
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled || refreshing}
      onClick={handleRefresh}
    >
      <svg
        className="live-refresh-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        focusable="false"
      >
        <path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.69 2.8l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8ZM5.23 4.74A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.69-2.8L5.23 4.74Z" />
      </svg>
    </button>
  )
}
