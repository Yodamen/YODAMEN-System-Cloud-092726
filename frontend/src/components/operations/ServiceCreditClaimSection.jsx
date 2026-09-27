import SearchableSelect from '../common/SearchableSelect'
import { useMemo, useState } from 'react'

function creditLabel(credit) {
  const available = Number(credit?.available_sessions || 0)
  return `${credit?.service_name || 'Service Credit'} · ${available} available`
}

export default function ServiceCreditClaimSection({
  customerSelected = false,
  credits = [],
  selectedLines = [],
  personnel = [],
  services = [],
  onAdd,
  onRemove,
}) {
  const [builderOpen, setBuilderOpen] = useState(false)
  const [creditId, setCreditId] = useState('')
  const [sessions, setSessions] = useState(1)
  const [staffId, setStaffId] = useState('')

  const selectableCredits = useMemo(
    () => credits.filter((credit) => Number(credit.available_sessions || 0) > 0),
    [credits],
  )

  const groups = useMemo(() => {
    const grouped = new Map()

    selectedLines.forEach((line) => {
      if (!line.service_credit_id) return
      const key = `${line.service_credit_id}:${line.assigned_personnel_id || ''}`
      const existing = grouped.get(key)
      if (existing) {
        existing.quantity += 1
        return
      }

      const credit = credits.find((row) => String(row.id) === String(line.service_credit_id))
      const service = services.find((row) => String(row.id) === String(line.service_id))
      const staff = personnel.find((row) => String(row.id) === String(line.assigned_personnel_id || ''))

      grouped.set(key, {
        key,
        serviceCreditId: String(line.service_credit_id || ''),
        assignedPersonnelId: String(line.assigned_personnel_id || ''),
        serviceName: credit?.service_name || service?.name || 'Service Credit',
        staffName: staff?.name || '',
        quantity: 1,
      })
    })

    return Array.from(grouped.values())
  }, [selectedLines, credits, services, personnel])

  const selectedCredit = credits.find((row) => String(row.id) === String(creditId))
  const maxSessions = Math.max(1, Number(selectedCredit?.available_sessions || 1))
  const availableBalanceCount = selectableCredits.length
  const helper = !customerSelected
    ? 'Select a customer to check available service credits.'
    : availableBalanceCount
      ? `${availableBalanceCount} service credit balance${availableBalanceCount === 1 ? '' : 's'} available.`
      : 'No available service credit balance for this customer.'

  function openBuilder() {
    if (!selectableCredits.length) return
    setCreditId('')
    setSessions(1)
    setStaffId('')
    setBuilderOpen(true)
  }

  function closeBuilder() {
    setBuilderOpen(false)
    setCreditId('')
    setSessions(1)
    setStaffId('')
  }

  function addClaim() {
    if (!selectedCredit) return
    const quantity = Math.min(maxSessions, Math.max(1, Number(sessions || 1)))
    onAdd?.({ credit: selectedCredit, sessions: quantity, assignedPersonnelId: staffId })
    closeBuilder()
  }

  return (
    <section className="transaction-form-card service-credit-claim-card form-span-2">
      <div className="transaction-section-title">
        <div>
          <h3>Service Credit</h3>
          <p>{helper}</p>
        </div>
        <button
          type="button"
          className="secondary-button transaction-section-button"
          disabled={!customerSelected || !selectableCredits.length}
          onClick={openBuilder}
        >
          Claim Service Credit
        </button>
      </div>

      {builderOpen ? (
        <div className="credit-claim-builder">
          <label className="form-field">
            <span>Available Credit *</span>
            <SearchableSelect value={creditId} onChange={(event) => { setCreditId(event.target.value); setSessions(1) }}>
              <option value="">Select service credit</option>
              {selectableCredits.map((credit) => (
                <option key={credit.id} value={credit.id}>{creditLabel(credit)}</option>
              ))}
            </SearchableSelect>
          </label>
          <label className="form-field">
            <span>Sessions</span>
            <input
              type="number"
              min="1"
              max={maxSessions}
              value={sessions}
              onChange={(event) => setSessions(event.target.value)}
            />
          </label>
          <label className="form-field">
            <span>Staff</span>
            <SearchableSelect value={staffId} onChange={(event) => setStaffId(event.target.value)}>
              <option value="">Unassigned</option>
              {personnel.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
            </SearchableSelect>
          </label>
          <div className="credit-builder-actions">
            <button type="button" className="secondary-button" onClick={closeBuilder}>Cancel</button>
            <button type="button" className="primary-button" disabled={!creditId} onClick={addClaim}>Add</button>
          </div>
        </div>
      ) : null}

      <div className="transaction-line-items">
        {groups.length ? groups.map((group) => (
          <div className="credit-claim-row" key={group.key}>
            <div>
              <strong>{group.serviceName}</strong>
              <span>Service Credit · Qty {group.quantity}{group.staffName ? ` · ${group.staffName}` : ''}</span>
            </div>
            <button
              type="button"
              className="text-danger-button"
              onClick={() => onRemove?.(group.serviceCreditId, group.assignedPersonnelId)}
            >
              Remove
            </button>
          </div>
        )) : <div className="transaction-inline-empty">No service credit claimed.</div>}
      </div>
    </section>
  )
}
