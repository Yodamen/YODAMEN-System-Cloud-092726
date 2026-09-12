export default function ActionMenu({ items = [] }) {
  const visibleItems = items.filter(Boolean)

  if (!visibleItems.length) {
    return <span className="table-subline">—</span>
  }

  function handleChange(event) {
    const index = Number(event.target.value)
    const item = visibleItems[index]
    event.target.value = ''
    item?.onClick?.()
  }

  return (
    <select className="action-select" defaultValue="" onChange={handleChange} aria-label="Actions">
      <option value="" disabled>Actions</option>
      {visibleItems.map((item, index) => (
        <option key={`${item.label}-${index}`} value={index} disabled={item.disabled}>
          {item.label}
        </option>
      ))}
    </select>
  )
}
