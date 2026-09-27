import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import '../expense/accounting-expense.css'

const blank = {
  id: null,
  code: '',
  name: '',
  account_type: 'OPERATING_EXPENSE',
  financial_statement_group: '',
  account_group: 'General & Admin',
  normal_balance: 'DEBIT',
  posting_type: 'POSTING',
  cash_flow_classification: 'OPERATING',
  is_opex: true,
  is_cash_account: false,
  is_funding_source: false,
  notes: '',
  system_key: null,
}

function pretty(value) {
  return String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

export default function ChartOfAccountsPage() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState({ accounts: [], options: {} })
  const [filters, setFilters] = useState({ search: '', account_type: '', active: 'ACTIVE' })
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const load = useCallback(async (next = filters) => {
    const response = await api.get('/v1/accounting/chart-of-accounts', {
      params: {
        search: next.search || undefined,
        account_type: next.account_type || undefined,
        active: next.active === 'ALL' ? undefined : next.active === 'ACTIVE',
      },
    })
    setData(response.data)
  }, [filters])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load chart of accounts.'))).finally(() => setLoading(false))
  }, [])

  const activeCount = useMemo(() => data.accounts.filter((row) => row.active).length, [data.accounts])
  const opexCount = useMemo(() => data.accounts.filter((row) => row.is_opex).length, [data.accounts])
  const cashCount = useMemo(() => data.accounts.filter((row) => row.is_cash_account).length, [data.accounts])

  async function apply(event) {
    event.preventDefault()
    setLoading(true)
    setMessage('')
    try { await load(filters) } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to load chart of accounts.')) } finally { setLoading(false) }
  }

  function openCreate() { setForm({ ...blank }) }
  function openEdit(row) { setForm({ ...row, notes: row.notes || '' }) }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const payload = {
        ...form,
        is_opex: Boolean(form.is_opex),
        is_cash_account: Boolean(form.is_cash_account),
        is_funding_source: Boolean(form.is_funding_source),
        cash_flow_classification: form.cash_flow_classification || null,
        financial_statement_group: form.financial_statement_group || null,
      }
      if (form.id) await api.put(`/v1/accounting/chart-of-accounts/${form.id}`, payload)
      else await api.post('/v1/accounting/chart-of-accounts', payload)
      setForm(null)
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save account.'))
    } finally { setSaving(false) }
  }

  async function toggle(row) {
    setMessage('')
    try {
      await api.patch(`/v1/accounting/chart-of-accounts/${row.id}/toggle`)
      await load(filters)
    } catch (error) { setMessage(getApiErrorMessage(error, 'Unable to update account status.')) }
  }

  return <div className="page-stack accounting-page">
    <section className="page-heading live-page-heading">
      <div>
        <h1 className="live-page-title">Chart of Accounts <PageRefreshButton label="Refresh accounts" disabled={loading || saving} onRefresh={() => load(filters)} /></h1>
        <p>Accounting master list used by manual entries and automatic operational journals.</p>
      </div>
      {hasPermission('coa', 'CREATE') ? <button className="primary-button" type="button" onClick={openCreate}>Add Account</button> : null}
    </section>

    {message ? <div className="form-alert error">{message}</div> : null}

    <section className="metric-grid accounting-metric-grid">
      <article className="metric-card"><span>Accounts Shown</span><strong>{data.accounts.length}</strong><small>Current filters</small></article>
      <article className="metric-card"><span>Active Accounts</span><strong>{activeCount}</strong><small>Available for posting</small></article>
      <article className="metric-card"><span>OPEX Accounts</span><strong>{opexCount}</strong><small>Operating expense classification</small></article>
      <article className="metric-card"><span>Cash Accounts</span><strong>{cashCount}</strong><small>Cash, bank, e-wallet and petty cash</small></article>
    </section>

    <section className="content-card">
      <form className="accounting-filter-grid" onSubmit={apply}>
        <label className="form-field accounting-filter-search"><span>Search</span><input value={filters.search} onChange={(e) => setFilters((c) => ({ ...c, search: e.target.value }))} placeholder="Code, account or group" /></label>
        <label className="form-field"><span>Account Type</span><SearchableSelect value={filters.account_type} onChange={(e) => setFilters((c) => ({ ...c, account_type: e.target.value }))}><option value="">All Types</option>{(data.options.account_types || []).map((value) => <option key={value} value={value}>{pretty(value)}</option>)}</SearchableSelect></label>
        <label className="form-field"><span>Status</span><SearchableSelect value={filters.active} onChange={(e) => setFilters((c) => ({ ...c, active: e.target.value }))}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option><option value="ALL">All</option></SearchableSelect></label>
        <div className="accounting-filter-actions"><button className="secondary-button" type="submit">Apply</button></div>
      </form>
    </section>

    <section className="content-card accounting-table-card">
      <div className="table-wrap"><table className="data-table accounting-table">
        <thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th>Group</th><th>FS Group</th><th>Normal Balance</th><th>Cash Flow</th><th>OPEX</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          {data.accounts.map((row) => <tr key={row.id}>
            <td><strong>{row.code}</strong></td>
            <td><strong>{row.name}</strong><span className="table-subline">{row.system_key ? 'System account' : 'Manual / direct posting account'}</span></td>
            <td>{pretty(row.account_type)}</td>
            <td>{row.account_group || '—'}</td>
            <td>{(data.options.financial_statement_groups || []).find((g)=>g.code===row.financial_statement_group)?.name || row.financial_statement_group || '—'}</td>
            <td>{pretty(row.normal_balance)}</td>
            <td>{pretty(row.cash_flow_classification)}</td>
            <td>{row.is_opex ? 'Yes' : 'No'}</td>
            <td><span className={`status-pill ${row.active ? 'success' : 'neutral'}`}>{row.active ? 'ACTIVE' : 'INACTIVE'}</span></td>
            <td><ActionMenu items={[
              hasPermission('coa', 'EDIT') ? { label: 'Edit Account', onClick: () => openEdit(row) } : null,
              hasPermission('coa', 'TOGGLE') ? { label: row.active ? 'Deactivate' : 'Activate', onClick: () => toggle(row), disabled: Boolean(row.system_key && row.active) } : null,
            ]} /></td>
          </tr>)}
          {!data.accounts.length && !loading ? <tr><td colSpan="10" className="empty-table-cell">No accounts match the selected filters.</td></tr> : null}
        </tbody>
      </table></div>
    </section>

    {form ? <Modal wide className="accounting-modal" title={form.id ? 'Edit Account' : 'Add Account'} subtitle={form.system_key ? 'System-mapped account. Structural fields are protected after activation.' : 'Create a posting or header account for the business.'} onClose={() => setForm(null)}>
      <form onSubmit={save}>
        <div className="form-grid accounting-account-form">
          <label className="form-field"><span>Account Code</span><input required value={form.code} disabled={Boolean(form.system_key)} onChange={(e) => setForm((c) => ({ ...c, code: e.target.value }))} /></label>
          <label className="form-field accounting-span-2"><span>Account Name</span><input required value={form.name} onChange={(e) => setForm((c) => ({ ...c, name: e.target.value }))} /></label>
          <label className="form-field"><span>Account Type</span><SearchableSelect required disabled={Boolean(form.system_key)} value={form.account_type} onChange={(e) => setForm((c) => ({ ...c, account_type: e.target.value }))}>{(data.options.account_types || []).map((value) => <option key={value} value={value}>{pretty(value)}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Account Group</span><input value={form.account_group || ''} onChange={(e) => setForm((c) => ({ ...c, account_group: e.target.value }))} /></label>
          <label className="form-field"><span>Financial Statement Group</span><SearchableSelect value={form.financial_statement_group || ''} onChange={(e) => setForm((c) => ({ ...c, financial_statement_group: e.target.value }))}><option value="">Unassigned / Default</option>{(data.options.financial_statement_groups || []).map((group) => <option key={group.code} value={group.code}>{group.name}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Normal Balance</span><SearchableSelect disabled={Boolean(form.system_key)} value={form.normal_balance} onChange={(e) => setForm((c) => ({ ...c, normal_balance: e.target.value }))}>{(data.options.normal_balances || []).map((value) => <option key={value}>{value}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Posting Type</span><SearchableSelect disabled={Boolean(form.system_key)} value={form.posting_type} onChange={(e) => setForm((c) => ({ ...c, posting_type: e.target.value }))}>{(data.options.posting_types || []).map((value) => <option key={value}>{value}</option>)}</SearchableSelect></label>
          <label className="form-field"><span>Cash Flow Classification</span><SearchableSelect value={form.cash_flow_classification || ''} onChange={(e) => setForm((c) => ({ ...c, cash_flow_classification: e.target.value }))}><option value="">None</option>{(data.options.cash_flow_classifications || []).map((value) => <option key={value}>{pretty(value)}</option>)}</SearchableSelect></label>
          <label className="toggle-field"><input type="checkbox" checked={Boolean(form.is_opex)} onChange={(e) => setForm((c) => ({ ...c, is_opex: e.target.checked }))} /><span>Operating Expense (OPEX)</span></label>
          <label className="toggle-field"><input type="checkbox" checked={Boolean(form.is_cash_account)} disabled={Boolean(form.system_key)} onChange={(e) => setForm((c) => ({ ...c, is_cash_account: e.target.checked }))} /><span>Cash / Payment Account</span></label>
          <label className="toggle-field"><input type="checkbox" checked={Boolean(form.is_funding_source)} disabled={['CASH_ON_HAND','CASH_WITH_MANAGER','POS_CLEARING','OWNERS_CAPITAL','LOANS_PAYABLE_OWNER'].includes(form.system_key) || !['ASSET','LIABILITY','EQUITY'].includes(form.account_type) || form.posting_type !== 'POSTING'} onChange={(e) => setForm((c) => ({ ...c, is_funding_source: e.target.checked }))} /><span>Operating Fund Source</span></label>
          <label className="form-field accounting-span-all"><span>Notes</span><textarea rows="3" value={form.notes || ''} onChange={(e) => setForm((c) => ({ ...c, notes: e.target.value }))} /></label>
        </div>
        <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save Account'}</button></div>
      </form>
    </Modal> : null}
  </div>
}
