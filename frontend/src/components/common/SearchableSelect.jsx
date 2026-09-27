import { Children, Fragment, cloneElement, isValidElement, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

function flattenOptions(children) {
  const rows = []
  Children.forEach(children, (child) => {
    if (!isValidElement(child)) return
    if (child.type === 'option') {
      const raw = child.props.value ?? child.props.children ?? ''
      const label = Children.toArray(child.props.children).join('')
      rows.push({
        value: String(raw ?? ''),
        label: String(label ?? raw ?? ''),
        disabled: Boolean(child.props.disabled),
        original: child,
      })
      return
    }
    if (child.type === 'optgroup' || child.type === Fragment) {
      rows.push(...flattenOptions(child.props.children))
    }
  })
  return rows
}

export default function SearchableSelect({
  children,
  value,
  defaultValue = '',
  onChange,
  onBlur,
  onFocus,
  disabled = false,
  required = false,
  name,
  id,
  className = '',
  'aria-label': ariaLabel,
  title,
  ...rest
}) {
  const options = useMemo(() => flattenOptions(children), [children])
  const selectedValue = String(value ?? defaultValue ?? '')
  const selected = options.find((option) => option.value === selectedValue) || null
  const placeholder = options.find((option) => option.value === '')?.label || 'Select option'
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const [menuStyle, setMenuStyle] = useState({})
  const rootRef = useRef(null)
  const inputRef = useRef(null)

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const selectable = options.filter((option) => option.value !== '')
    if (!needle) return selectable
    return selectable.filter((option) => `${option.label} ${option.value}`.toLowerCase().includes(needle))
  }, [options, query])

  useEffect(() => {
    if (!open) return undefined
    const reposition = () => {
      const rect = rootRef.current?.getBoundingClientRect()
      if (!rect) return
      const availableBelow = window.innerHeight - rect.bottom
      const maxHeight = Math.min(300, Math.max(140, availableBelow - 12))
      const longestLabelLength = options.reduce((max, option) => Math.max(max, String(option.label || '').length), 0)
      const contentWidth = Math.min(520, Math.max(rect.width, (longestLabelLength * 7.2) + 72))
      const viewportWidth = Math.max(rect.width, window.innerWidth - rect.left - 12)
      const menuWidth = Math.min(contentWidth, viewportWidth)
      setMenuStyle({
        position: 'fixed',
        left: rect.left,
        top: rect.bottom + 4,
        width: menuWidth,
        maxWidth: 'calc(100vw - 24px)',
        maxHeight,
        zIndex: 10050,
      })
    }
    reposition()
    window.addEventListener('resize', reposition)
    window.addEventListener('scroll', reposition, true)
    return () => {
      window.removeEventListener('resize', reposition)
      window.removeEventListener('scroll', reposition, true)
    }
  }, [open, options])

  useEffect(() => {
    if (!open) setQuery('')
  }, [selectedValue, open])

  useEffect(() => {
    const handlePointer = (event) => {
      if (!open) return
      const menu = document.querySelector(`[data-searchable-menu="${id || name || ''}-${rootRef.current?.dataset.searchableUid || ''}"]`)
      if (rootRef.current?.contains(event.target) || menu?.contains(event.target)) return
      setOpen(false)
      setQuery('')
      setActiveIndex(-1)
    }
    document.addEventListener('mousedown', handlePointer)
    return () => document.removeEventListener('mousedown', handlePointer)
  }, [open, id, name])

  const emit = (nextValue) => {
    const event = {
      target: { value: nextValue, name, id },
      currentTarget: { value: nextValue, name, id },
      preventDefault() {},
      stopPropagation() {},
    }
    onChange?.(event)
  }

  const choose = (option) => {
    if (!option || option.disabled) return
    emit(option.value)
    setOpen(false)
    setQuery('')
    setActiveIndex(-1)
    requestAnimationFrame(() => inputRef.current?.focus())
  }

  const openMenu = () => {
    if (disabled) return
    setOpen(true)
    setQuery('')
    setActiveIndex(-1)
  }

  const handleKeyDown = (event) => {
    if (disabled) return
    if (!open && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
      event.preventDefault()
      openMenu()
      return
    }
    if (!open) return
    if (event.key === 'Escape') {
      event.preventDefault()
      setOpen(false)
      setQuery('')
      setActiveIndex(-1)
      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((current) => Math.min(filtered.length - 1, current + 1))
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((current) => Math.max(0, current - 1))
      return
    }
    if (event.key === 'Enter' && activeIndex >= 0) {
      event.preventDefault()
      choose(filtered[activeIndex])
    }
  }

  const uid = useMemo(() => Math.random().toString(36).slice(2), [])
  const menuKey = `${id || name || ''}-${uid}`
  const shownText = open ? query : (selected?.label || '')

  return <div ref={rootRef} data-searchable-uid={uid} className={`searchable-select ${disabled ? 'is-disabled' : ''} ${open ? 'is-open' : ''} ${className}`.trim()}>
    <input
      {...rest}
      ref={inputRef}
      id={id}
      name={name ? `${name}__search` : undefined}
      className="searchable-select-input"
      type="text"
      autoComplete="off"
      value={shownText}
      placeholder={placeholder}
      disabled={disabled}
      required={required && !selectedValue}
      aria-label={ariaLabel}
      aria-expanded={open}
      aria-haspopup="listbox"
      title={title}
      onFocus={(event) => { onFocus?.(event); openMenu() }}
      onClick={openMenu}
      onChange={(event) => {
        setQuery(event.target.value)
        setOpen(true)
        setActiveIndex(0)
        if (event.target.value === '' && options.some((option) => option.value === '')) emit('')
      }}
      onBlur={(event) => { onBlur?.(event); setOpen(false); setQuery(''); setActiveIndex(-1) }}
      onKeyDown={handleKeyDown}
    />
    <span className="searchable-select-chevron" aria-hidden="true">⌄</span>
    <select tabIndex={-1} aria-hidden="true" className="searchable-select-native" value={selectedValue} onChange={() => {}} name={name}>
      {Children.map(children, (child) => isValidElement(child) ? cloneElement(child) : child)}
    </select>
    {open && typeof document !== 'undefined' ? createPortal(
      <div data-searchable-menu={menuKey} className="searchable-select-menu" style={menuStyle} role="listbox">
        {filtered.length ? filtered.map((option, index) => <button
          key={`${option.value}-${index}`}
          type="button"
          className={`searchable-select-option ${option.value === selectedValue ? 'is-selected' : ''} ${index === activeIndex ? 'is-active' : ''}`}
          disabled={option.disabled}
          onMouseDown={(event) => event.preventDefault()}
          onMouseEnter={() => setActiveIndex(index)}
          onClick={() => choose(option)}
        >
          <span title={option.label}>{option.label}</span>
          {option.value === selectedValue ? <small>Selected</small> : null}
        </button>) : <div className="searchable-select-empty">No matching options</div>}
      </div>,
      document.body,
    ) : null}
  </div>
}
