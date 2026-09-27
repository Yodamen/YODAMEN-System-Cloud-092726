import SearchableSelect from '../../components/common/SearchableSelect'
import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import Modal from '../../components/common/Modal'

const fieldOptions = [
  ['SERVICE_AVAILED', 'Service Availed'],
  ['SERVICE_CATEGORY_AVAILED', 'Service Category Availed'],
  ['PACKAGE_PURCHASED', 'Package Purchased'],
  ['VISIT_COUNT', 'Completed Visit Count'],
  ['CUSTOMER_TYPE', 'Customer Type'],
  ['LIFETIME_VALUE', 'Lifetime Value'],
  ['CUSTOMER_CLASS', 'Customer Class'],
  ['LAST_VISIT', 'Last Visit'],
  ['PROMO_QUALIFIED', 'Qualifies for Promo Code'],
  ['LEAD_SOURCE', 'Lead Source'],
  ['BIRTH_MONTH', 'Birth Month'],
  ['CUSTOMER_STATUS', 'Customer Status'],
]

const eventOperators = [
  ['ANY_OF', 'has availed any of'],
  ['ALL_OF', 'has availed all of'],
  ['NONE_OF', 'has not availed any of'],
  ['NEVER', 'has never availed'],
  ['COUNT_AT_LEAST', 'has availed at least'],
  ['COUNT_AT_MOST', 'has availed at most'],
  ['LAST_MORE_THAN_DAYS', 'last availed more than'],
  ['LAST_WITHIN_DAYS', 'last availed within'],
]

const packageOperators = eventOperators.map(([value, label]) => [value, label.replace('availed', 'purchased')])

const numericOperators = [
  ['AT_LEAST', 'is at least'],
  ['AT_MOST', 'is at most'],
  ['EQUALS', 'equals'],
  ['BETWEEN', 'is between'],
]

const lastVisitOperators = [
  ['MORE_THAN_DAYS', 'was more than'],
  ['WITHIN_DAYS', 'was within'],
  ['BETWEEN_DAYS', 'was between'],
  ['NEVER_VISITED', 'never visited'],
]

const listOperators = [['IN', 'is any of'], ['NOT_IN', 'is not any of']]

function newCondition(field = 'SERVICE_AVAILED') {
  return {
    field,
    operator: 'ANY_OF',
    values: [],
    value: '',
    value2: '',
    period_type: 'ALL_TIME',
    period_days: '',
    date_from: '',
    date_to: '',
  }
}

function newGroup() {
  return { match: 'ALL', conditions: [newCondition()] }
}

function normalizeForField(condition, field) {
  const next = { ...newCondition(field) }
  if (field === 'VISIT_COUNT' || field === 'LIFETIME_VALUE') next.operator = 'AT_LEAST'
  if (field === 'LAST_VISIT') next.operator = 'MORE_THAN_DAYS'
  if (field === 'PROMO_QUALIFIED') next.operator = 'QUALIFIES'
  if (['LEAD_SOURCE', 'BIRTH_MONTH', 'CUSTOMER_STATUS', 'CUSTOMER_TYPE', 'CUSTOMER_CLASS'].includes(field)) next.operator = 'IN'
  return next
}

function operatorOptions(field) {
  if (field === 'PACKAGE_PURCHASED') return packageOperators
  if (field === 'SERVICE_AVAILED' || field === 'SERVICE_CATEGORY_AVAILED') return eventOperators
  if (field === 'VISIT_COUNT' || field === 'LIFETIME_VALUE') return numericOperators
  if (field === 'LAST_VISIT') return lastVisitOperators
  if (field === 'PROMO_QUALIFIED') return [['QUALIFIES', 'qualifies for']]
  return listOperators
}

function valueOptions(field, options) {
  if (field === 'SERVICE_AVAILED') return (options.services || []).filter((item) => !item.is_package).map((item) => ({ value: item.id, label: `${item.name}${item.category ? ` · ${item.category}` : ''}` }))
  if (field === 'SERVICE_CATEGORY_AVAILED') return (options.service_categories || []).map((item) => ({ value: item.id, label: item.label }))
  if (field === 'PACKAGE_PURCHASED') return (options.services || []).filter((item) => item.is_package).map((item) => ({ value: item.id, label: item.name }))
  if (field === 'LEAD_SOURCE') return (options.lead_sources || []).map((item) => ({ value: item.code, label: item.label }))
  if (field === 'BIRTH_MONTH') return (options.birth_months || []).map((item) => ({ value: item.value, label: item.label }))
  if (field === 'CUSTOMER_STATUS') return (options.customer_statuses || []).map((item) => ({ value: item, label: item }))
  if (field === 'CUSTOMER_TYPE') return (options.customer_types || []).map((item) => ({ value: item.value, label: item.label }))
  if (field === 'CUSTOMER_CLASS') return (options.customer_classes || []).map((item) => ({ value: item.value, label: item.label }))
  return []
}


function CheckboxDropdown({ items = [], selected = [], placeholder = 'Select values', onChange }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [menuStyle, setMenuStyle] = useState(null)
  const rootRef = useRef(null)
  const menuRef = useRef(null)
  const selectedStrings = useMemo(() => (selected || []).map(String), [selected])
  const selectedSet = useMemo(() => new Set(selectedStrings), [selectedStrings])
  const selectedLabels = useMemo(
    () => items.filter((item) => selectedSet.has(String(item.value))).map((item) => item.label),
    [items, selectedSet],
  )
  const filteredItems = useMemo(() => {
    const needle = search.trim().toLowerCase()
    if (!needle) return items
    return items.filter((item) => String(item.label || '').toLowerCase().includes(needle))
  }, [items, search])

  useEffect(() => {
    function handleDocumentPointerDown(event) {
      const insideTrigger = rootRef.current?.contains(event.target)
      const insideMenu = menuRef.current?.contains(event.target)
      if (!insideTrigger && !insideMenu) setOpen(false)
    }
    document.addEventListener('mousedown', handleDocumentPointerDown)
    return () => document.removeEventListener('mousedown', handleDocumentPointerDown)
  }, [])

  useEffect(() => {
    if (!open) {
      setMenuStyle(null)
      return undefined
    }

    function updatePosition() {
      const rect = rootRef.current?.getBoundingClientRect()
      if (!rect) return

      const viewportPadding = 10
      const desiredWidth = Math.max(rect.width, 360)
      const width = Math.min(desiredWidth, window.innerWidth - (viewportPadding * 2))
      const left = Math.min(
        Math.max(viewportPadding, rect.left),
        Math.max(viewportPadding, window.innerWidth - width - viewportPadding),
      )
      const spaceBelow = window.innerHeight - rect.bottom - viewportPadding
      const spaceAbove = rect.top - viewportPadding
      const openUpward = spaceBelow < 300 && spaceAbove > spaceBelow

      setMenuStyle({
        left: `${left}px`,
        top: `${openUpward ? rect.top - 6 : rect.bottom + 6}px`,
        width: `${width}px`,
        transform: openUpward ? 'translateY(-100%)' : 'none',
      })
    }

    updatePosition()
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)
    return () => {
      window.removeEventListener('resize', updatePosition)
      window.removeEventListener('scroll', updatePosition, true)
    }
  }, [open])

  function toggleValue(value) {
    const key = String(value)
    const next = selectedSet.has(key)
      ? selectedStrings.filter((item) => item !== key)
      : [...selectedStrings, key]
    onChange(next)
  }

  function selectAllVisible() {
    const next = new Set(selectedStrings)
    filteredItems.forEach((item) => next.add(String(item.value)))
    onChange(Array.from(next))
  }

  const buttonText = selectedLabels.length === 0
    ? placeholder
    : selectedLabels.length === 1
      ? selectedLabels[0]
      : `${selectedLabels.length} selected`

  const menu = open && menuStyle ? (
    <div
      className="audience-checkbox-dropdown-menu audience-checkbox-dropdown-menu-portal"
      role="listbox"
      aria-multiselectable="true"
      ref={menuRef}
      style={menuStyle}
    >
      <div className="audience-checkbox-dropdown-menu-head">
        <div>
          <strong>Select options</strong>
          <span>{selectedLabels.length ? `${selectedLabels.length} selected` : 'No selection yet'}</span>
        </div>
        <button type="button" className="audience-dropdown-close" aria-label="Close options" onClick={() => setOpen(false)}>×</button>
      </div>

      {items.length > 8 ? (
        <div className="audience-checkbox-dropdown-search">
          <input
            type="search"
            value={search}
            placeholder="Search options..."
            onChange={(event) => setSearch(event.target.value)}
            autoFocus
          />
        </div>
      ) : null}

      <div className="audience-checkbox-dropdown-actions">
        <button type="button" className="audience-dropdown-action-button" onClick={selectAllVisible}>Select All</button>
        <button type="button" className="audience-dropdown-action-button subtle" onClick={() => onChange([])}>Clear</button>
      </div>

      <div className="audience-checkbox-dropdown-options">
        {filteredItems.length ? filteredItems.map((item) => {
          const key = String(item.value)
          const checked = selectedSet.has(key)
          return (
            <label className={`audience-checkbox-dropdown-option${checked ? ' selected' : ''}`} key={key}>
              <input type="checkbox" checked={checked} onChange={() => toggleValue(key)} />
              <span>{item.label}</span>
            </label>
          )
        }) : <div className="audience-checkbox-dropdown-empty">No matching options.</div>}
      </div>
    </div>
  ) : null

  return (
    <div className={`audience-checkbox-dropdown${open ? ' open' : ''}`} ref={rootRef}>
      <button
        type="button"
        className="audience-checkbox-dropdown-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <span className={selectedLabels.length ? '' : 'placeholder'}>{buttonText}</span>
        <span className="audience-checkbox-dropdown-chevron" aria-hidden="true">▾</span>
      </button>
      {menu ? createPortal(menu, document.body) : null}
    </div>
  )
}

function isEventField(field) {
  return ['SERVICE_AVAILED', 'SERVICE_CATEGORY_AVAILED', 'PACKAGE_PURCHASED'].includes(field)
}

function needsMultiValues(condition) {
  if (!isEventField(condition.field)) return ['LEAD_SOURCE', 'BIRTH_MONTH', 'CUSTOMER_STATUS', 'CUSTOMER_TYPE', 'CUSTOMER_CLASS'].includes(condition.field)
  return !['NEVER'].includes(condition.operator)
}

function needsSingleNumber(condition) {
  return ['VISIT_COUNT', 'LIFETIME_VALUE'].includes(condition.field)
    || (isEventField(condition.field) && ['COUNT_AT_LEAST', 'COUNT_AT_MOST', 'LAST_MORE_THAN_DAYS', 'LAST_WITHIN_DAYS'].includes(condition.operator))
    || (condition.field === 'LAST_VISIT' && ['MORE_THAN_DAYS', 'WITHIN_DAYS'].includes(condition.operator))
}

function needsSecondNumber(condition) {
  return (['VISIT_COUNT', 'LIFETIME_VALUE'].includes(condition.field) && condition.operator === 'BETWEEN')
    || (condition.field === 'LAST_VISIT' && condition.operator === 'BETWEEN_DAYS')
}

function unitLabel(condition) {
  if (condition.field === 'LIFETIME_VALUE') return '₱'
  if (condition.field === 'LAST_VISIT' || ['LAST_MORE_THAN_DAYS', 'LAST_WITHIN_DAYS'].includes(condition.operator)) return 'days'
  if (['COUNT_AT_LEAST', 'COUNT_AT_MOST'].includes(condition.operator)) return 'times'
  return ''
}

function dependencyNote(field) {
  if (field === 'CUSTOMER_TYPE') return 'Customer Type is calculated live from Completed Visit Count using the configured Customer Mix thresholds.'
  if (field === 'CUSTOMER_CLASS') return 'Customer Class is calculated live from Lifetime Value using the configured Customer Mix thresholds.'
  if (field === 'VISIT_COUNT') return 'This same completed-visit metric also determines Customer Type.'
  if (field === 'LIFETIME_VALUE') return 'This same lifetime-value metric also determines Customer Class.'
  return ''
}

export default function CustomerAudienceBuilder({ options = {}, initialDefinition = null, onClose, onApply, applying = false }) {
  const [definition, setDefinition] = useState(initialDefinition || { match: 'ALL', groups: [newGroup()] })
  const totalConditions = useMemo(() => definition.groups.reduce((sum, group) => sum + group.conditions.length, 0), [definition])

  function updateGroup(groupIndex, patch) {
    setDefinition((current) => ({
      ...current,
      groups: current.groups.map((group, index) => index === groupIndex ? { ...group, ...patch } : group),
    }))
  }

  function updateCondition(groupIndex, conditionIndex, patch) {
    setDefinition((current) => ({
      ...current,
      groups: current.groups.map((group, gIndex) => gIndex !== groupIndex ? group : {
        ...group,
        conditions: group.conditions.map((condition, cIndex) => cIndex === conditionIndex ? { ...condition, ...patch } : condition),
      }),
    }))
  }

  function changeField(groupIndex, conditionIndex, field) {
    updateCondition(groupIndex, conditionIndex, normalizeForField({}, field))
  }

  function addCondition(groupIndex) {
    if (totalConditions >= 50) return
    updateGroup(groupIndex, { conditions: [...definition.groups[groupIndex].conditions, newCondition()] })
  }

  function removeCondition(groupIndex, conditionIndex) {
    const group = definition.groups[groupIndex]
    if (group.conditions.length === 1) return
    updateGroup(groupIndex, { conditions: group.conditions.filter((_, index) => index !== conditionIndex) })
  }

  function addGroup() {
    if (definition.groups.length >= 5) return
    setDefinition((current) => ({ ...current, groups: [...current.groups, newGroup()] }))
  }

  function removeGroup(groupIndex) {
    if (definition.groups.length === 1) return
    setDefinition((current) => ({ ...current, groups: current.groups.filter((_, index) => index !== groupIndex) }))
  }

  return (
    <Modal
      title="Smart Customer Audience"
      subtitle="Build customer segments using grouped ALL / ANY conditions. Each group acts like a set of parentheses."
      onClose={onClose}
      wide
      className="customer-audience-modal"
    >
      <div className="audience-root-rule">
        <div className="audience-root-copy">
          <span className="audience-eyebrow">Audience Logic</span>
          <strong>How should the groups work together?</strong>
          <small>Use ALL for a narrower audience. Use ANY when matching one group is enough.</small>
        </div>
        <label className="audience-root-select">
          <span>Between Groups</span>
          <SearchableSelect value={definition.match} onChange={(event) => setDefinition((current) => ({ ...current, match: event.target.value }))}>
            <option value="ALL">ALL groups (AND)</option>
            <option value="ANY">ANY group (OR)</option>
          </SearchableSelect>
        </label>
      </div>

      <div className="audience-groups">
        {definition.groups.map((group, groupIndex) => (
          <section className="audience-group" key={`group-${groupIndex}`}>
            <header className="audience-group-header">
              <div className="audience-group-title">
                <span className="audience-group-number">{groupIndex + 1}</span>
                <div>
                  <strong>Audience Group {groupIndex + 1}</strong>
                  <span>{group.match === 'ALL' ? 'Every condition below must match' : 'Any condition below may match'}</span>
                </div>
              </div>
              <label className="audience-group-match">
                <span>Match</span>
                <SearchableSelect value={group.match} onChange={(event) => updateGroup(groupIndex, { match: event.target.value })}>
                  <option value="ALL">ALL conditions</option>
                  <option value="ANY">ANY condition</option>
                </SearchableSelect>
              </label>
              {definition.groups.length > 1 ? <button type="button" className="secondary-button mini audience-remove-group" onClick={() => removeGroup(groupIndex)}>Remove Group</button> : null}
            </header>

            <div className="audience-condition-list">
              {group.conditions.map((condition, conditionIndex) => {
                const values = valueOptions(condition.field, options)
                const showPeriod = isEventField(condition.field)
                return (
                  <div className="audience-condition" key={`condition-${groupIndex}-${conditionIndex}`}>
                    <span className="audience-condition-index">Condition {conditionIndex + 1}</span>
                    <div className="audience-condition-main">
                      <label>
                        <span>Field</span>
                        <SearchableSelect value={condition.field} onChange={(event) => changeField(groupIndex, conditionIndex, event.target.value)}>
                          {fieldOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SearchableSelect>
                      </label>
                      <label>
                        <span>Operator</span>
                        <SearchableSelect value={condition.operator} onChange={(event) => updateCondition(groupIndex, conditionIndex, { operator: event.target.value })}>
                          {operatorOptions(condition.field).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </SearchableSelect>
                      </label>

                      {condition.field === 'PROMO_QUALIFIED' ? (
                        <label className="audience-value-wide">
                          <span>Promo Code</span>
                          <SearchableSelect value={condition.value || ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { value: event.target.value })}>
                            <option value="">Select promo code</option>
                            {(options.promo_codes || []).map((code) => <option key={code} value={code}>{code}</option>)}
                          </SearchableSelect>
                        </label>
                      ) : null}

                      {needsMultiValues(condition) ? (
                        <div className="audience-value-wide audience-value-field">
                          <span className="audience-value-field-label">Value</span>
                          <CheckboxDropdown
                            items={values}
                            selected={condition.values || []}
                            placeholder="Select one or more"
                            onChange={(nextValues) => updateCondition(groupIndex, conditionIndex, { values: nextValues })}
                          />
                        </div>
                      ) : null}

                      {needsSingleNumber(condition) ? (
                        <label>
                          <span>{condition.field === 'LIFETIME_VALUE' ? 'Amount' : 'Value'} {unitLabel(condition) ? `(${unitLabel(condition)})` : ''}</span>
                          <input type="number" min="0" step={condition.field === 'LIFETIME_VALUE' ? '0.01' : '1'} value={condition.value ?? ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { value: event.target.value })} />
                        </label>
                      ) : null}

                      {needsSecondNumber(condition) ? (
                        <label>
                          <span>To {condition.field === 'LIFETIME_VALUE' ? '(₱)' : condition.field === 'LAST_VISIT' ? '(days)' : ''}</span>
                          <input type="number" min="0" step={condition.field === 'LIFETIME_VALUE' ? '0.01' : '1'} value={condition.value2 ?? ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { value2: event.target.value })} />
                        </label>
                      ) : null}
                    </div>

                    {dependencyNote(condition.field) ? (
                      <div className="audience-derived-note">
                        <span className="audience-derived-dot" aria-hidden="true" />
                        {dependencyNote(condition.field)}
                      </div>
                    ) : null}

                    {showPeriod ? (
                      <div className="audience-period-row">
                        <label>
                          <span>Period</span>
                          <SearchableSelect value={condition.period_type || 'ALL_TIME'} onChange={(event) => updateCondition(groupIndex, conditionIndex, { period_type: event.target.value })}>
                            <option value="ALL_TIME">All Time</option>
                            <option value="LAST_N_DAYS">Last N Days</option>
                            <option value="BETWEEN_DATES">Between Dates</option>
                          </SearchableSelect>
                        </label>
                        {condition.period_type === 'LAST_N_DAYS' ? <label><span>Days</span><input type="number" min="1" value={condition.period_days || ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { period_days: event.target.value })} /></label> : null}
                        {condition.period_type === 'BETWEEN_DATES' ? <><label><span>From</span><input type="date" value={condition.date_from || ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { date_from: event.target.value })} /></label><label><span>To</span><input type="date" value={condition.date_to || ''} onChange={(event) => updateCondition(groupIndex, conditionIndex, { date_to: event.target.value })} /></label></> : null}
                      </div>
                    ) : null}

                    {group.conditions.length > 1 ? <button type="button" className="audience-remove-condition" onClick={() => removeCondition(groupIndex, conditionIndex)} aria-label="Remove condition">Remove</button> : null}
                  </div>
                )
              })}
            </div>

            <button type="button" className="secondary-button audience-add-condition" onClick={() => addCondition(groupIndex)}>+ Add Condition</button>
          </section>
        ))}
      </div>

      <div className="audience-builder-footer">
        <button type="button" className="secondary-button" onClick={addGroup} disabled={definition.groups.length >= 5}>+ Add Group</button>
        <div>
          <button type="button" className="secondary-button" onClick={onClose}>Cancel</button>
          <button type="button" className="primary-button" disabled={applying} onClick={() => onApply(definition)}>{applying ? 'Applying...' : 'Apply Audience'}</button>
        </div>
      </div>
    </Modal>
  )
}
