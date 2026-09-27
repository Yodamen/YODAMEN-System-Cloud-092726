import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

export default function CheckboxMultiSelect({
  label,
  options = [],
  values = [],
  onChange,
  allLabel = null,
  disabled = false,
  getValue = (option) => option.id,
  getLabel = (option) => option.name,
  searchPlaceholder = 'Search...',
  placeholder = null,
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [menuStyle, setMenuStyle] = useState({})
  const rootRef = useRef(null)
  const menuRef = useRef(null)
  const selected = useMemo(() => new Set((values || []).map((value) => String(value))), [values])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    if (!term) return options
    return options.filter((option) => String(getLabel(option) || '').toLowerCase().includes(term))
  }, [getLabel, options, search])

  const selectedOptions = useMemo(
    () => options.filter((option) => selected.has(String(getValue(option)))),
    [getValue, options, selected],
  )

  const summary = useMemo(() => {
    if (!selectedOptions.length) return allLabel || placeholder || `Select ${label}`
    if (selectedOptions.length === 1) return String(getLabel(selectedOptions[0]) || '')
    if (selectedOptions.length === 2) return selectedOptions.map((option) => getLabel(option)).join(', ')
    return `${selectedOptions.length} selected`
  }, [allLabel, getLabel, label, placeholder, selectedOptions])

  useEffect(() => {
    if (!open) return undefined

    const reposition = () => {
      const rect = rootRef.current?.getBoundingClientRect()
      if (!rect) return
      const longestLabelLength = options.reduce(
        (max, option) => Math.max(max, String(getLabel(option) || '').length),
        0,
      )
      const desiredWidth = Math.min(560, Math.max(rect.width, (longestLabelLength * 7.2) + 84))
      const availableRight = Math.max(rect.width, window.innerWidth - rect.left - 12)
      const width = Math.min(desiredWidth, availableRight)
      const availableBelow = window.innerHeight - rect.bottom
      const maxHeight = Math.min(360, Math.max(180, availableBelow - 12))

      setMenuStyle({
        position: 'fixed',
        left: rect.left,
        top: rect.bottom + 4,
        width,
        maxWidth: 'calc(100vw - 24px)',
        maxHeight,
        zIndex: 10060,
      })
    }

    reposition()
    window.addEventListener('resize', reposition)
    window.addEventListener('scroll', reposition, true)
    return () => {
      window.removeEventListener('resize', reposition)
      window.removeEventListener('scroll', reposition, true)
    }
  }, [getLabel, open, options])

  useEffect(() => {
    if (!open) return undefined
    const handlePointer = (event) => {
      if (rootRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) return
      setOpen(false)
      setSearch('')
    }
    const handleKey = (event) => {
      if (event.key === 'Escape') {
        setOpen(false)
        setSearch('')
      }
    }
    document.addEventListener('mousedown', handlePointer)
    document.addEventListener('keydown', handleKey)
    return () => {
      document.removeEventListener('mousedown', handlePointer)
      document.removeEventListener('keydown', handleKey)
    }
  }, [open])

  function toggle(value) {
    const key = String(value)
    const next = selected.has(key)
      ? (values || []).filter((item) => String(item) !== key)
      : [...(values || []), value]
    onChange(next)
  }

  function clearAll() {
    onChange([])
  }

  function toggleOpen() {
    if (disabled) return
    setOpen((current) => !current)
    if (open) setSearch('')
  }

  return (
    <div ref={rootRef} className={`form-field checkbox-multi-field${disabled ? ' is-disabled' : ''}${open ? ' is-open' : ''}`}>
      <span className="checkbox-multi-label">{label}</span>
      <button
        type="button"
        className="checkbox-multi-trigger"
        onClick={toggleOpen}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        title={summary}
      >
        <span className={`checkbox-multi-trigger-text${selectedOptions.length ? ' has-selection' : ''}`}>{summary}</span>
        <span className="checkbox-multi-chevron" aria-hidden="true">⌄</span>
      </button>

      {open && typeof document !== 'undefined' ? createPortal(
        <div ref={menuRef} className="checkbox-multi-menu" style={menuStyle} role="listbox" aria-multiselectable="true">
          {options.length > 6 ? (
            <div className="checkbox-multi-search-wrap">
              <input
                className="checkbox-multi-search"
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={searchPlaceholder}
                autoFocus
              />
            </div>
          ) : null}

          <div className="checkbox-multi-options">
            {allLabel ? (
              <label className="checkbox-multi-option checkbox-multi-all">
                <input type="checkbox" checked={(values || []).length === 0} onChange={clearAll} />
                <span>{allLabel}</span>
              </label>
            ) : null}

            {filtered.map((option) => {
              const value = getValue(option)
              const optionLabel = getLabel(option)
              return (
                <label className="checkbox-multi-option" key={String(value)}>
                  <input type="checkbox" checked={selected.has(String(value))} onChange={() => toggle(value)} />
                  <span title={optionLabel}>{optionLabel}</span>
                </label>
              )
            })}

            {!filtered.length ? <div className="checkbox-multi-empty">No matching options.</div> : null}
          </div>

          <div className="checkbox-multi-menu-footer">
            <span>{selectedOptions.length ? `${selectedOptions.length} selected` : (allLabel || 'No specific selection')}</span>
            <button type="button" className="checkbox-multi-done" onClick={() => { setOpen(false); setSearch('') }}>Done</button>
          </div>
        </div>,
        document.body,
      ) : null}
    </div>
  )
}
