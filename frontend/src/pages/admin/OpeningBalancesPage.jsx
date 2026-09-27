import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, getApiErrorMessage } from '../../api/client'
import ActionConfirmationModal from '../../components/common/ActionConfirmationModal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import './opening-balances.css'

const localDateIso = (date = new Date()) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const peso = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0))
const emptyReceivable = () => ({ customer_id: '', amount: '', reference: '', notes: '' })
const emptyPackage = () => ({ customer_id: '', service_id: '', remaining_sessions: '', remaining_service_value: '', outstanding_balance: '', expiry_date: '', notes: '' })

export default function OpeningBalancesPage() {
  const [searchParams] = useSearchParams()
  const [data, setData] = useState({ branches: [], cash_accounts: [], products: [], customers: [], package_services: [], readiness: {} })
  const [branchId, setBranchId] = useState('')
  const [cutoverDate, setCutoverDate] = useState(localDateIso())
  const [cash, setCash] = useState({})
  const [inventory, setInventory] = useState({})
  const [receivables, setReceivables] = useState([])
  const [packages, setPackages] = useState([])
  const [notes, setNotes] = useState('')
  const [tab, setTab] = useState('CASH')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (nextBranchId = branchId) => {
    try {
      setError('')
      const response = await api.get('/v1/admin/opening-balances', { params: nextBranchId ? { branch_id: nextBranchId } : {} })
      const payload = response.data
      setData(payload)
      const resolved = String(payload.selected_branch_id || nextBranchId || payload.branches?.[0]?.id || '')
      setBranchId(resolved)
      setCash(Object.fromEntries((payload.cash_accounts || []).map((row) => [row.id, ''])))
      setInventory(Object.fromEntries((payload.products || []).map((row) => [row.id, { quantity: '', unit_cost: row.unit_cost || '' }])))
      setReceivables([])
      setPackages([])
      setNotes('')
    } catch (e) {
      setError(getApiErrorMessage(e))
    }
  }, [branchId])

  useEffect(() => { load(searchParams.get('branch_id') || '') }, [])

  const cashTotal = useMemo(() => (data.cash_accounts || []).reduce((sum, row) => sum + Number(cash[row.id] || 0), 0), [cash, data.cash_accounts])
  const inventoryTotal = useMemo(() => (data.products || []).reduce((sum, row) => {
    const entry = inventory[row.id] || {}
    return sum + Number(entry.quantity || 0) * Number(entry.unit_cost || 0)
  }, 0), [inventory, data.products])
  const standaloneReceivableTotal = useMemo(() => receivables.reduce((sum, row) => sum + Number(row.amount || 0), 0), [receivables])
  const packageReceivableTotal = useMemo(() => packages.reduce((sum, row) => sum + Number(row.outstanding_balance || 0), 0), [packages])
  const receivablesTotal = standaloneReceivableTotal + packageReceivableTotal
  const deferredPackageTotal = useMemo(() => packages.reduce((sum, row) => sum + Number(row.remaining_service_value || 0), 0), [packages])
  const totalAssets = cashTotal + inventoryTotal + receivablesTotal
  const openingEquity = totalAssets - deferredPackageTotal
  const openingMagnitude = totalAssets + deferredPackageTotal

  const existing = data.existing
  const blocked = existing || !data.readiness?.can_post

  function patchReceivable(index, field, value) {
    setReceivables((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row))
  }

  function patchPackage(index, field, value) {
    setPackages((current) => current.map((row, rowIndex) => {
      if (rowIndex !== index) return row
      const next = { ...row, [field]: value }
      if (field === 'service_id') {
        const service = (data.package_services || []).find((item) => String(item.id) === String(value))
        if (service) {
          next.remaining_sessions = String(service.total_sessions || '')
          next.remaining_service_value = service.current_price == null ? '' : String(service.current_price)
        }
      }
      if (field === 'remaining_sessions') {
        const service = (data.package_services || []).find((item) => String(item.id) === String(next.service_id))
        if (service?.current_price != null && Number(service.total_sessions || 0) > 0) {
          const sessions = Math.max(0, Math.min(Number(value || 0), Number(service.total_sessions)))
          next.remaining_service_value = sessions > 0 ? String((Number(service.current_price) * sessions / Number(service.total_sessions)).toFixed(2)) : ''
        }
      }
      return next
    }))
  }

  const postOpening = async () => {
    setBusy(true)
    setError('')
    try {
      const response = await api.post('/v1/admin/opening-balances', {
        branch_id: Number(branchId),
        cutover_date: cutoverDate,
        cash_balances: (data.cash_accounts || []).map((row) => ({ account_id: row.id, amount: Number(cash[row.id] || 0) })),
        inventory_balances: (data.products || []).map((row) => ({ retail_product_id: row.id, quantity: Number(inventory[row.id]?.quantity || 0), unit_cost: Number(inventory[row.id]?.unit_cost || 0) })),
        customer_receivables: receivables
          .filter((row) => row.customer_id && Number(row.amount || 0) > 0)
          .map((row) => ({ customer_id: Number(row.customer_id), amount: Number(row.amount || 0), reference: row.reference || null, notes: row.notes || null })),
        active_packages: packages
          .filter((row) => row.customer_id && row.service_id && Number(row.remaining_sessions || 0) > 0)
          .map((row) => ({
            customer_id: Number(row.customer_id),
            service_id: Number(row.service_id),
            remaining_sessions: Number(row.remaining_sessions || 0),
            remaining_service_value: Number(row.remaining_service_value || 0),
            outstanding_balance: Number(row.outstanding_balance || 0),
            expiry_date: row.expiry_date || null,
            notes: row.notes || null,
          })),
        notes: notes || null,
      })
      setSuccess(response.data.message || 'Opening balances posted.')
      setConfirming(false)
      await load(branchId)
    } catch (e) {
      setError(getApiErrorMessage(e))
      setConfirming(false)
    } finally {
      setBusy(false)
    }
  }

  return <div className="page-stack opening-balances-page">
    <div className="page-heading opening-balances-heading">
      <div>
        <p className="eyebrow">Administration</p>
        <h1 className="live-page-title">Opening Balances <PageRefreshButton label="Refresh Opening Balances" onRefresh={() => load(branchId)} /></h1>
        <p>One-time current-state cutover. Configure master data first, then establish what the business actually has, owes, or still needs to deliver at go-live.</p>
      </div>
    </div>

    {error ? <div className="form-alert error">{error}</div> : null}
    {success ? <div className="form-alert success">{success}</div> : null}

    <section className="content-card opening-cutover-header">
      <label className="form-field"><span>Branch</span><SearchableSelect value={branchId} onChange={(e) => { setBranchId(e.target.value); load(e.target.value) }}>{(data.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
      <label className="form-field"><span>Cutover Date</span><input type="date" max={localDateIso()} value={cutoverDate} disabled={!!existing} onChange={(e) => setCutoverDate(e.target.value)} /></label>
      <div className="opening-cutover-status"><span>Status</span><strong>{existing ? 'Posted' : data.readiness?.can_post ? 'Ready' : 'Not Ready'}</strong></div>
    </section>

    {existing ? <section className="content-card opening-existing opening-existing-five">
      <div><span>Cash & Banks</span><strong>{peso(existing.cash_total)}</strong></div>
      <div><span>Inventory</span><strong>{peso(existing.inventory_total)}</strong></div>
      <div><span>Customer AR</span><strong>{peso(existing.receivables_total)}</strong></div>
      <div><span>Deferred Packages</span><strong>{peso(existing.deferred_package_total)}</strong></div>
      <div><span>Opening Equity</span><strong>{peso(existing.opening_equity)}</strong></div>
      <p>Opening balances for this branch are locked. New activity must now use the normal operational and accounting workflows.</p>
    </section> : null}

    {!existing && !data.readiness?.can_post ? <div className="form-alert error">Opening balances can only be posted before operational journals or inventory movements exist for the selected branch. For a hard restart, clear transactional data first, complete the opening setup, then resume operations.</div> : null}

    <div className="booking-tabs opening-tabs" role="tablist" aria-label="Opening balance sections">
      <button type="button" className={tab === 'CASH' ? 'active' : ''} onClick={() => setTab('CASH')}>Cash & Funds</button>
      <button type="button" className={tab === 'INVENTORY' ? 'active' : ''} onClick={() => setTab('INVENTORY')}>Inventory</button>
      <button type="button" className={tab === 'RECEIVABLES' ? 'active' : ''} onClick={() => setTab('RECEIVABLES')}>Customer Balances</button>
      <button type="button" className={tab === 'PACKAGES' ? 'active' : ''} onClick={() => setTab('PACKAGES')}>Active Packages</button>
    </div>

    {tab === 'CASH' ? <section className="content-card">
      <div className="section-heading"><div><h2>Cash & Fund Balances</h2><p>Enter the actual balance of each cash, bank, e-wallet, and petty cash account as of the cutover date.</p></div><strong>{peso(cashTotal)}</strong></div>
      <div className="table-wrap"><table className="data-table opening-balance-table"><thead><tr><th>Account</th><th>Beginning Balance</th></tr></thead><tbody>{(data.cash_accounts || []).map((row) => <tr key={row.id}><td><strong>{row.code}</strong> · {row.name}</td><td><input type="number" min="0" step="0.01" disabled={blocked} value={cash[row.id] ?? ''} onChange={(e) => setCash((current) => ({ ...current, [row.id]: e.target.value }))} /></td></tr>)}</tbody></table></div>
    </section> : null}

    {tab === 'INVENTORY' ? <section className="content-card">
      <div className="section-heading"><div><h2>Beginning Inventory</h2><p>Enter actual quantity in Basic UOM and its beginning Basic UOM cost.</p></div><strong>{peso(inventoryTotal)}</strong></div>
      <div className="table-wrap"><table className="data-table opening-inventory-table"><thead><tr><th>Item</th><th>Basic UOM</th><th>Beginning Qty</th><th>Basic UOM Cost</th><th>Opening Value</th><th>Inventory Account</th></tr></thead><tbody>{(data.products || []).map((row) => { const entry = inventory[row.id] || {}; const value = Number(entry.quantity || 0) * Number(entry.unit_cost || 0); return <tr key={row.id}><td><strong>{row.name}</strong><span className="table-subline">{row.sku}</span></td><td>{row.basic_uom}</td><td><input type="number" min="0" step="0.0001" disabled={blocked} value={entry.quantity ?? ''} onChange={(e) => setInventory((current) => ({ ...current, [row.id]: { ...(current[row.id] || {}), quantity: e.target.value } }))} /></td><td><input type="number" min="0" step="0.0001" disabled={blocked} value={entry.unit_cost ?? ''} onChange={(e) => setInventory((current) => ({ ...current, [row.id]: { ...(current[row.id] || {}), unit_cost: e.target.value } }))} /></td><td><strong>{peso(value)}</strong></td><td>{row.inventory_account || <span className="status-pill warning">Needs Mapping</span>}</td></tr> })}</tbody></table></div>
    </section> : null}

    {tab === 'RECEIVABLES' ? <section className="content-card opening-entry-section">
      <div className="section-heading">
        <div><h2>Customer Balances</h2><p>Enter only amounts still collectible at cutover. Use Active Packages for balances tied to an existing package.</p></div>
        <div className="opening-section-actions"><strong>{peso(standaloneReceivableTotal)}</strong><button type="button" className="secondary-button mini" disabled={blocked} onClick={() => setReceivables((current) => [...current, emptyReceivable()])}>+ Add Balance</button></div>
      </div>
      {receivables.length ? <div className="opening-entry-list">{receivables.map((row, index) => <div className="opening-entry-card opening-receivable-card" key={`receivable-${index}`}>
        <label className="form-field opening-customer-field"><span>Customer</span><SearchableSelect disabled={blocked} value={row.customer_id} onChange={(e) => patchReceivable(index, 'customer_id', e.target.value)}><option value="">Select customer</option>{(data.customers || []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}{customer.code ? ` · ${customer.code}` : ''}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Outstanding Balance</span><input type="number" min="0" step="0.01" disabled={blocked} value={row.amount} onChange={(e) => patchReceivable(index, 'amount', e.target.value)} placeholder="0.00" /></label>
        <label className="form-field"><span>Reference</span><input disabled={blocked} value={row.reference} onChange={(e) => patchReceivable(index, 'reference', e.target.value)} placeholder="Old invoice / note" /></label>
        <label className="form-field"><span>Notes</span><input disabled={blocked} value={row.notes} onChange={(e) => patchReceivable(index, 'notes', e.target.value)} placeholder="Optional" /></label>
        <div className="opening-entry-remove"><button type="button" className="line-remove-button" disabled={blocked} onClick={() => setReceivables((current) => current.filter((_, rowIndex) => rowIndex !== index))}>Remove</button></div>
      </div>)}</div> : <div className="inline-empty opening-empty-state">No standalone customer balances added. Add only receivables that are still outstanding on the cutover date.</div>}
    </section> : null}

    {tab === 'PACKAGES' ? <section className="content-card opening-entry-section">
      <div className="section-heading">
        <div><h2>Active Packages / Service Credits</h2><p>Enter only what remains usable at cutover. Historical bookings, used sessions, and old payments do not need to be recreated.</p></div>
        <div className="opening-section-actions"><strong>{peso(deferredPackageTotal)}</strong><button type="button" className="secondary-button mini" disabled={blocked} onClick={() => setPackages((current) => [...current, emptyPackage()])}>+ Add Package</button></div>
      </div>
      {packages.length ? <div className="opening-entry-list">{packages.map((row, index) => {
        const service = (data.package_services || []).find((item) => String(item.id) === String(row.service_id))
        return <div className="opening-entry-card opening-package-card" key={`package-${index}`}>
          <label className="form-field opening-customer-field"><span>Customer</span><SearchableSelect disabled={blocked} value={row.customer_id} onChange={(e) => patchPackage(index, 'customer_id', e.target.value)}><option value="">Select customer</option>{(data.customers || []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}{customer.code ? ` · ${customer.code}` : ''}</option>)}</SearchableSelect></label>
          <label className="form-field opening-package-field"><span>Package</span><SearchableSelect disabled={blocked} value={row.service_id} onChange={(e) => patchPackage(index, 'service_id', e.target.value)}><option value="">Select package</option>{(data.package_services || []).map((item) => <option key={item.id} value={item.id}>{item.name} · {item.total_sessions} sessions</option>)}</SearchableSelect>{service ? <small>Configured total: {service.total_sessions} sessions{service.current_price != null ? ` · ${peso(service.current_price)}` : ''}</small> : null}</label>
          <label className="form-field"><span>Remaining Sessions</span><input type="number" min="1" max={service?.total_sessions || undefined} step="1" disabled={blocked} value={row.remaining_sessions} onChange={(e) => patchPackage(index, 'remaining_sessions', e.target.value)} /></label>
          <label className="form-field"><span>Remaining Service Value</span><input type="number" min="0" step="0.01" disabled={blocked} value={row.remaining_service_value} onChange={(e) => patchPackage(index, 'remaining_service_value', e.target.value)} placeholder="0.00" /></label>
          <label className="form-field"><span>Outstanding Balance</span><input type="number" min="0" step="0.01" disabled={blocked} value={row.outstanding_balance} onChange={(e) => patchPackage(index, 'outstanding_balance', e.target.value)} placeholder="0.00" /></label>
          <label className="form-field"><span>Expiry</span><input type="date" min={cutoverDate} disabled={blocked} value={row.expiry_date} onChange={(e) => patchPackage(index, 'expiry_date', e.target.value)} /></label>
          <div className="opening-entry-remove"><button type="button" className="line-remove-button" disabled={blocked} onClick={() => setPackages((current) => current.filter((_, rowIndex) => rowIndex !== index))}>Remove</button></div>
        </div>
      })}</div> : <div className="inline-empty opening-empty-state">No active packages added. Add only packages with sessions still available on the cutover date.</div>}
    </section> : null}

    {!existing ? <section className="content-card opening-post-card">
      <div className="opening-summary opening-summary-five">
        <div><span>Cash & Funds</span><strong>{peso(cashTotal)}</strong></div>
        <div><span>Inventory</span><strong>{peso(inventoryTotal)}</strong></div>
        <div><span>Customer AR</span><strong>{peso(receivablesTotal)}</strong></div>
        <div><span>Deferred Packages</span><strong>{peso(deferredPackageTotal)}</strong></div>
        <div><span>Opening Equity</span><strong>{peso(openingEquity)}</strong></div>
      </div>
      <label className="form-field"><span>Notes</span><textarea rows="3" disabled={blocked} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional cutover note" /></label>
      <div className="opening-post-actions"><button className="primary-button" type="button" disabled={blocked || openingMagnitude <= 0} onClick={() => setConfirming(true)}>Post Opening Balances</button></div>
    </section> : null}

    {confirming ? <ActionConfirmationModal title="Post Opening Balances?" subtitle="One-time system cutover" message="This will establish the selected branch's current state and lock its opening setup. Historical transactions are not recreated." details={[{ label: 'Cutover Date', value: cutoverDate }, { label: 'Cash & Funds', value: peso(cashTotal) }, { label: 'Inventory', value: peso(inventoryTotal) }, { label: 'Customer AR', value: peso(receivablesTotal) }, { label: 'Unused Package Value', value: peso(deferredPackageTotal) }, { label: 'Opening Equity', value: peso(openingEquity) }]} confirmLabel="Post Opening Balances" cancelLabel="Cancel" busy={busy} onCancel={() => setConfirming(false)} onConfirm={postOpening} /> : null}
  </div>
}
