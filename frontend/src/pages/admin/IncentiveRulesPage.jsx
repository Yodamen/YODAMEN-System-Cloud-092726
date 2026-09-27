import SearchableSelect from '../../components/common/SearchableSelect'
import CheckboxMultiSelect from '../../components/common/CheckboxMultiSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { formatDisplayDate, formatPeso } from '../../utils/formatters'

function todayLocal() {
  const d = new Date(); const y=d.getFullYear(); const m=String(d.getMonth()+1).padStart(2,'0'); const day=String(d.getDate()).padStart(2,'0'); return `${y}-${m}-${day}`
}
function blank() { return { id:null, rule_name:'', branch_id:'', metric:'SALE_NET', target_scope:'ALL', personnel_id:'', personnel_ids:[], service_ids:[], service_category_reference_ids:[], threshold_value:'0', reward_type:'FIXED', reward_value:'', effective_from:todayLocal(), effective_until:'', priority:'0', stackable:true, status:'ACTIVE', notes:'' } }
function fromRule(r){ return { id:r.id, rule_name:r.rule_name||'', branch_id:r.branch?.id?String(r.branch.id):'', metric:r.metric||'SALE_NET', target_scope:r.target_scope||'ALL', personnel_id:r.personnel?.id?String(r.personnel.id):'', personnel_ids:(r.personnel_ids||(r.personnel?.id?[r.personnel.id]:[])).map(String), service_ids:(r.service_ids||[]).map(String), service_category_reference_ids:(r.service_category_reference_ids||[]).map(String), threshold_value:String(r.threshold_value??0), reward_type:r.reward_type||'FIXED', reward_value:String(r.reward_value??''), effective_from:r.effective_from||todayLocal(), effective_until:r.effective_until||'', priority:String(r.priority??0), stackable:r.stackable!==false, status:r.status||'ACTIVE', notes:r.notes||'' } }
const metricLabel={SALE_NET:'Assigned Net Sales',SERVICE_NET:'Assigned Service Net Sales',RETAIL_NET:'Assigned Retail Net Sales',ITEM_COUNT:'Assigned Item Quantity'}

function targetText(rule){
  const people=rule.personnel_list||[]
  if(rule.target_scope!=='PERSONNEL') return 'All Assigned Personnel'
  return people.length?people.map((row)=>row.name).join(', '):(rule.personnel?.name||'Selected Personnel')
}
function serviceScopeText(rule){
  const services=rule.service_list||[]
  const categories=rule.service_category_list||[]
  const parts=[]
  if(services.length)parts.push(services.map((row)=>row.name).join(', '))
  if(categories.length)parts.push(categories.map((row)=>row.name).join(', '))
  return parts.join(' + ')||'All Services'
}

export default function IncentiveRulesPage(){
  const { hasPermission }=useAuth(); const [rules,setRules]=useState([]); const [options,setOptions]=useState({branches:[],personnel:[],services:[],service_categories:[]}); const [form,setForm]=useState(null); const [loading,setLoading]=useState(true); const [saving,setSaving]=useState(false); const [message,setMessage]=useState(''); const [search,setSearch]=useState('')
  const load=useCallback(async()=>{setLoading(true);setMessage('');try{const r=await api.get('/v1/admin/incentive-rules');setRules(r.data.rules||[]);setOptions(r.data.options||{branches:[],personnel:[],services:[],service_categories:[]})}catch(e){setMessage(getApiErrorMessage(e,'Unable to load incentive rules.'))}finally{setLoading(false)}},[])
  useEffect(()=>{load()},[load])
  const filtered=useMemo(()=>rules.filter(r=>`${r.rule_name} ${r.code} ${targetText(r)} ${serviceScopeText(r)} ${metricLabel[r.metric]||r.metric}`.toLowerCase().includes(search.toLowerCase())),[rules,search])
  const set=(k,v)=>setForm(c=>{
    const next={...c,[k]:v}
    if(k==='target_scope'&&v!=='PERSONNEL'){next.personnel_id='';next.personnel_ids=[]}
    if(k==='metric'&&v==='RETAIL_NET'){next.service_ids=[];next.service_category_reference_ids=[]}
    return next
  })
  async function save(e){e.preventDefault();setSaving(true);setMessage('');const payload={...form,branch_id:form.branch_id?Number(form.branch_id):null,personnel_id:null,personnel_ids:form.target_scope==='PERSONNEL'?(form.personnel_ids||[]).map(Number):[],service_ids:(form.service_ids||[]).map(Number),service_category_reference_ids:(form.service_category_reference_ids||[]).map(Number),threshold_value:Number(form.threshold_value||0),reward_value:Number(form.reward_value||0),priority:Number(form.priority||0),stackable:Boolean(form.stackable),effective_until:form.effective_until||null};delete payload.id;try{if(form.id)await api.put(`/v1/admin/incentive-rules/${form.id}`,payload);else await api.post('/v1/admin/incentive-rules',payload);setForm(null);await load()}catch(err){setMessage(getApiErrorMessage(err,'Unable to save incentive rule.'))}finally{setSaving(false)}}
  async function toggle(r){try{await api.patch(`/v1/admin/incentive-rules/${r.id}/toggle`);await load()}catch(e){setMessage(getApiErrorMessage(e,'Unable to update incentive rule.'))}}
  return <div className="page-stack">
    <section className="page-heading live-page-heading"><div><h1 className="live-page-title">Incentive Rules <PageRefreshButton label="Refresh incentive rules" disabled={loading||saving} onRefresh={load}/></h1><p className="page-subtitle">Automatic staff incentives generated from financially finalized sales. Incentive earnings flow into the existing payroll earning pipeline.</p></div>{hasPermission('incentive-rules','CREATE')?<button className="primary-button" onClick={()=>setForm(blank())}>Add Rule</button>:null}</section>
    <div className="form-alert warning">Rules evaluate per assigned personnel when a sale is finalized. Multiple eligible rules may stack unless an exclusive rule has Stackable turned off.</div>
    {message?<div className="form-alert error">{message}</div>:null}
    <section className="content-card"><div className="booking-filter-panel"><label className="booking-filter-field booking-search-field">Search<input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Rule, code, personnel, metric"/></label></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Rule</th><th>Metric</th><th>Target</th><th>Threshold</th><th>Reward</th><th>Effective</th><th>Status</th><th className="actions-column">Action</th></tr></thead><tbody>{filtered.map(r=><tr key={r.id}><td><strong>{r.rule_name}</strong><span className="table-subline">{r.code} · {r.branch?.name||'All Branches'}</span></td><td>{metricLabel[r.metric]||r.metric}<span className="table-subline">{r.metric==='RETAIL_NET'?'All Retail':serviceScopeText(r)}</span></td><td>{targetText(r)}</td><td>{r.metric==='ITEM_COUNT'?Number(r.threshold_value||0):formatPeso(r.threshold_value)}</td><td><strong>{r.reward_type==='PERCENTAGE'?`${Number(r.reward_value)}%`:formatPeso(r.reward_value)}</strong><span className="table-subline">{r.stackable?'Stackable':'Exclusive'}</span></td><td>{formatDisplayDate(r.effective_from)}<span className="table-subline">{r.effective_until?`to ${formatDisplayDate(r.effective_until)}`:'Open-ended'}</span></td><td><span className={`status-pill ${r.display_status==='ACTIVE'?'success':r.display_status==='SCHEDULED'?'warning':'neutral'}`}>{r.display_status}</span></td><td className="action-cell"><ActionMenu items={[hasPermission('incentive-rules','EDIT')?{label:'Edit',onClick:()=>setForm(fromRule(r))}:null,hasPermission('incentive-rules','TOGGLE')?{label:r.status==='ACTIVE'?'Deactivate':'Activate',onClick:()=>toggle(r)}:null]}/></td></tr>)}{!filtered.length?<tr><td colSpan="8"><div className="empty-state compact-empty">No incentive rules found.</div></td></tr>:null}</tbody></table></div></section>
    {form?<Modal title={form.id?'Edit Incentive Rule':'Add Incentive Rule'} onClose={()=>setForm(null)}><form className="form-grid" onSubmit={save}>
      <label className="form-field form-span-2">Rule Name<input value={form.rule_name} onChange={e=>set('rule_name',e.target.value)} required/></label>
      <label className="form-field">Branch<SearchableSelect value={form.branch_id} onChange={e=>set('branch_id',e.target.value)}><option value="">All Branches</option>{(options.branches||[]).map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</SearchableSelect></label>
      <label className="form-field">Metric<SearchableSelect value={form.metric} onChange={e=>set('metric',e.target.value)}>{Object.entries(metricLabel).map(([v,l])=><option key={v} value={v}>{l}</option>)}</SearchableSelect></label>
      <label className="form-field form-span-2">Target<SearchableSelect value={form.target_scope} onChange={e=>set('target_scope',e.target.value)}><option value="ALL">All Assigned Personnel</option><option value="PERSONNEL">Specific Personnel</option></SearchableSelect></label>
      {form.target_scope==='PERSONNEL'?<div className="form-span-2"><CheckboxMultiSelect label="Personnel" options={options.personnel||[]} values={form.personnel_ids} onChange={(values)=>set('personnel_ids',values.map(String))}/></div>:null}
      {form.metric!=='RETAIL_NET'?<div className="form-span-2 rule-scope-grid"><CheckboxMultiSelect label="Service Categories" options={options.service_categories||[]} values={form.service_category_reference_ids} onChange={(values)=>set('service_category_reference_ids',values.map(String))}/><CheckboxMultiSelect label="Services" options={options.services||[]} values={form.service_ids} onChange={(values)=>set('service_ids',values.map(String))}/></div>:null}
      <label className="form-field">Threshold<input type="number" min="0" step="0.01" value={form.threshold_value} onChange={e=>set('threshold_value',e.target.value)} required/></label>
      <label className="form-field">Reward Type<SearchableSelect value={form.reward_type} onChange={e=>set('reward_type',e.target.value)}><option value="FIXED">Fixed Amount</option><option value="PERCENTAGE">Percentage of Metric</option></SearchableSelect></label>
      <label className="form-field">Reward Value<input type="number" min="0.0001" step="0.0001" value={form.reward_value} onChange={e=>set('reward_value',e.target.value)} required/></label>
      <label className="form-field">Effective From<input type="date" value={form.effective_from} onChange={e=>set('effective_from',e.target.value)} required/></label>
      <label className="form-field">Effective Until<input type="date" value={form.effective_until} onChange={e=>set('effective_until',e.target.value)}/></label>
      <label className="form-field">Priority<input type="number" step="1" value={form.priority} onChange={e=>set('priority',e.target.value)}/></label>
      <label className="form-field incentive-stackable-field"><span>Stackable with other incentives</span><input type="checkbox" checked={Boolean(form.stackable)} onChange={e=>set('stackable',e.target.checked)}/></label>
      <label className="form-field">Status<SearchableSelect value={form.status} onChange={e=>set('status',e.target.value)}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
      <label className="form-field form-span-2">Notes<textarea value={form.notes} onChange={e=>set('notes',e.target.value)} rows="3"/></label>
      <div className="modal-actions form-span-2"><button type="button" className="secondary-button" onClick={()=>setForm(null)}>Cancel</button><button className="primary-button" disabled={saving}>{saving?'Saving...':'Save Rule'}</button></div>
    </form></Modal>:null}
  </div>
}
