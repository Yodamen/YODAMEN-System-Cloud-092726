import { useEffect, useMemo, useState } from 'react'
import { api } from '../../api/client'
import SearchableSelect from './SearchableSelect'

export default function WorkflowReasonSelect({
  context,
  value = '',
  onChange,
  label = 'Reason Category',
  required = true,
  disabled = false,
  className = '',
  includeBlank = true,
}) {
  const normalizedContext = String(context || '').trim().toUpperCase()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(Boolean(normalizedContext))

  useEffect(() => {
    let active = true

    if (!normalizedContext) {
      setRows([])
      setLoading(false)
      return () => { active = false }
    }

    setLoading(true)
    api.get('/v1/workflow-reasons', { params: { context: normalizedContext } })
      .then((response) => {
        if (!active) return
        setRows(response.data.reasons || [])
      })
      .catch(() => {
        if (!active) return
        setRows([])
      })
      .finally(() => { if (active) setLoading(false) })

    return () => { active = false }
  }, [normalizedContext])

  const options = useMemo(() => rows || [], [rows])

  return (
    <label className={`form-field ${className}`.trim()}>
      <span>{label}</span>
      <SearchableSelect value={value || ''} onChange={onChange} required={required} disabled={disabled || loading}>
        {includeBlank ? <option value="">{loading ? 'Loading reasons...' : 'Select reason category'}</option> : null}
        {options.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
      </SearchableSelect>
    </label>
  )
}
