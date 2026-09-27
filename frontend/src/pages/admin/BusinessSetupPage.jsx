import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import './business-setup.css'

const emptyBranch = { id: null, code: '', name: '', short_code: '', address: '', timezone: '' }

export default function BusinessSetupPage() {
  const { loadBootstrap, hasPermission } = useAuth()
  const [data, setData] = useState(null)
  const [business, setBusiness] = useState({ name: '', legal_name: '', timezone: 'Asia/Manila', currency: 'PHP' })
  const [branchForm, setBranchForm] = useState(null)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/business-setup')
    setData(response.data)
    setBusiness({
      name: response.data.business?.name || '',
      legal_name: response.data.business?.legal_name || '',
      timezone: response.data.business?.timezone || 'Asia/Manila',
      currency: response.data.business?.currency || 'PHP',
    })
  }, [])

  useEffect(() => {
    load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Business Setup.')))
  }, [load])

  async function saveBusiness(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    try {
      await api.put('/v1/admin/business-setup', business)
      await load()
      await loadBootstrap()
      setMessage('Business setup updated.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update Business Setup.'))
    } finally {
      setBusy(false)
    }
  }

  async function saveBranch(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    try {
      const payload = {
        ...branchForm,
        short_code: branchForm.short_code || null,
        address: branchForm.address || null,
        timezone: branchForm.timezone || null,
      }
      if (branchForm.id) await api.put(`/v1/admin/business-setup/branches/${branchForm.id}`, payload)
      else await api.post('/v1/admin/business-setup/branches', payload)
      setBranchForm(null)
      await load()
      await loadBootstrap()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save branch.'))
    } finally {
      setBusy(false)
    }
  }

  async function toggleBranch(row) {
    try {
      await api.patch(`/v1/admin/business-setup/branches/${row.id}/toggle`)
      await load()
      await loadBootstrap()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update branch status.'))
    }
  }

  return <div className="page-stack">
    <div className="page-heading"><div><p className="eyebrow">Administration</p><h1 className="live-page-title">Business Setup <PageRefreshButton label="Refresh business setup" onRefresh={load} /></h1><p>Maintain the business identity and operating branches used throughout the system.</p></div></div>
    {message ? <div className={message.includes('updated') ? 'form-alert success' : 'form-alert error'}>{message}</div> : null}

    <section className="content-card">
      <div className="section-heading"><div><h2>Business Profile</h2></div></div>
      <form className="admin-settings-grid" onSubmit={saveBusiness}>
        <label className="form-field"><span>Business Name</span><input required value={business.name} onChange={(e)=>setBusiness((c)=>({...c,name:e.target.value}))} /></label>
        <label className="form-field"><span>Legal Name</span><input value={business.legal_name} onChange={(e)=>setBusiness((c)=>({...c,legal_name:e.target.value}))} /></label>
        <label className="form-field"><span>Timezone</span><input required value={business.timezone} onChange={(e)=>setBusiness((c)=>({...c,timezone:e.target.value}))} /></label>
        <label className="form-field"><span>Currency</span><input required maxLength="3" value={business.currency} onChange={(e)=>setBusiness((c)=>({...c,currency:e.target.value.toUpperCase()}))} /></label>
        {hasPermission('business-setup','EDIT') ? <div className="modal-actions full-span"><button className="primary-button" disabled={busy}>Save Business Profile</button></div> : null}
      </form>
    </section>

    <section className="content-card">
      <div className="section-heading branches-section-heading">
        <div><h2>Branches</h2><p>Active branches are available in operational and payroll selectors.</p></div>
        {hasPermission('business-setup','MANAGE_BRANCHES') ? <button className="primary-button" type="button" onClick={()=>setBranchForm({...emptyBranch})}>Add Branch</button> : null}
      </div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Branch</th><th>Code</th><th>Address</th><th>Timezone</th><th>Status</th><th></th></tr></thead><tbody>{(data?.branches||[]).map((row)=><tr key={row.id}><td><strong>{row.name}</strong><span className="table-subline">{row.short_code||''}</span></td><td>{row.code}</td><td>{row.address||'—'}</td><td>{row.timezone||business.timezone}</td><td><span className={`status-pill ${row.status==='ACTIVE'?'success':'neutral'}`}>{row.status}</span></td><td>{hasPermission('business-setup','MANAGE_BRANCHES') ? <div className="toolbar-row"><button className="secondary-button" type="button" onClick={()=>setBranchForm({...row})}>Edit</button><button className="secondary-button" type="button" onClick={()=>toggleBranch(row)}>{row.status==='ACTIVE'?'Deactivate':'Activate'}</button></div>:null}</td></tr>)}</tbody></table></div>
      {!(data?.branches||[]).length ? <div className="empty-state">No branches found.</div> : null}
    </section>

    {branchForm ? <Modal title={branchForm.id ? 'Edit Branch' : 'Add Branch'} onClose={()=>setBranchForm(null)}><form className="branch-form-grid" onSubmit={saveBranch}>
      <label className="form-field"><span>Branch Code</span><input required value={branchForm.code} onChange={(e)=>setBranchForm((c)=>({...c,code:e.target.value.toUpperCase()}))} /></label>
      <label className="form-field"><span>Branch Name</span><input required value={branchForm.name} onChange={(e)=>setBranchForm((c)=>({...c,name:e.target.value}))} /></label>
      <label className="form-field"><span>Short Code</span><input value={branchForm.short_code||''} onChange={(e)=>setBranchForm((c)=>({...c,short_code:e.target.value}))} /></label>
      <label className="form-field"><span>Timezone</span><input value={branchForm.timezone||''} placeholder={business.timezone} onChange={(e)=>setBranchForm((c)=>({...c,timezone:e.target.value}))} /></label>
      <label className="form-field branch-address-field"><span>Address</span><textarea rows="3" value={branchForm.address||''} onChange={(e)=>setBranchForm((c)=>({...c,address:e.target.value}))} /></label>
      <div className="branch-form-actions"><button className="secondary-button" type="button" onClick={()=>setBranchForm(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy?'Saving...':'Save Branch'}</button></div>
    </form></Modal> : null}
  </div>
}
