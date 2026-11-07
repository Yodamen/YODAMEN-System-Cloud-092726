import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { api, getApiErrorMessage, initializeCsrf } from '../api/client'
import { useAuth } from '../context/AuthContext'
import yodamenLogo from '../assets/yodamen-horizontal.png'
import './setup.css'

const initialForm = { business_name: '', business_code: 'YDM', branch_name: 'Main Branch', username: '', display_name: '', access_code: '', access_code_confirmation: '' }

export default function SetupPage() {
  const { user, refreshSession } = useAuth()
  const [status, setStatus] = useState(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const [step, setStep] = useState(1)
  const [token, setToken] = useState('')
  const [form, setForm] = useState(initialForm)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [completed, setCompleted] = useState(null)

  useEffect(() => {
    let active = true
    api.get('/v1/setup').then(({ data }) => { if (active) setStatus(data) }).catch(() => { if (active) setLoadFailed(true) })
    return () => { active = false }
  }, [])

  if (user && !completed) return <Navigate to="/" replace />
  const change = (key) => (event) => setForm((current) => ({ ...current, [key]: key === 'business_code' ? event.target.value.toUpperCase() : event.target.value }))

  async function submit(event) {
    event.preventDefault()
    setError('')
    if (step === 1) { setStep(2); return }
    if (form.access_code !== form.access_code_confirmation) { setError('The access codes do not match.'); return }
    setBusy(true)
    try {
      await initializeCsrf()
      const { data } = await api.post('/v1/setup', form, { headers: { 'X-Yodamen-Setup-Token': token.trim() } })
      setCompleted(data)
      setToken('')
      setForm(initialForm)
      await refreshSession()
    } catch (failure) {
      setError(getApiErrorMessage(failure, 'Unable to finish setup. Check your connection and try again.'))
      if (failure.response?.data?.code === 'SETUP_CLOSED') setStatus((current) => ({ ...current, requires_setup: false }))
    } finally { setBusy(false) }
  }

  return <main className="setup-page">
    <section className="setup-card">
      <img className="setup-logo" src={yodamenLogo} alt="Yodamen" />
      {completed ? <>
        <p className="setup-eyebrow">Ready to start</p>
        <h1>Your business is ready</h1>
        <p>Your Super Admin account has been created. Your Premium trial includes {completed.subscription.trial_limit} activities, with no client records added.</p>
        <dl className="setup-summary"><dt>Business code</dt><dd>{completed.business_code}</dd><dt>Installation ID</dt><dd>{completed.installation_id}</dd></dl>
        <p>To purchase Basic, Standard or Premium, send these details to your provider. Activate the code under Admin → Subscription.</p>
        <Link className="primary-button setup-primary" to={user ? '/' : '/login'}>{user ? 'Open dashboard' : 'Continue to sign in'}</Link>
      </> : !status ? <>
        <h1>Set up your business</h1>
        <p role="status">{loadFailed ? 'Unable to check setup. Refresh this page to try again.' : 'Checking your installation…'}</p>
        {loadFailed && <Link to="/login">Back to sign in</Link>}
      </> : !status.requires_setup ? <>
        <h1>Setup is closed</h1><p>This installation is already configured or browser setup has not been enabled.</p>
        <Link className="primary-button setup-primary" to="/login">Go to sign in</Link>
      </> : <>
        <p className="setup-eyebrow">First-time setup</p>
        <h1>{step === 1 ? 'Make it your business' : 'Create your Super Admin'}</h1>
        <p className="setup-intro">Start with a clean workspace and a 50-activity Premium trial.</p>
        <ol className="setup-steps" aria-label="Setup progress"><li aria-current={step === 1 ? 'step' : undefined}>1 · Business</li><li aria-current={step === 2 ? 'step' : undefined}>2 · Administrator</li><li>3 · Ready</li></ol>
        {status.token_expired && <div className="setup-alert" role="alert">The setup code has expired. Rerun the installer to get a fresh code.</div>}
        {error && <div className="setup-alert" role="alert">{error}</div>}
        <form onSubmit={submit}>
          <fieldset disabled={busy || status.token_expired}>
            {step === 1 ? <>
              <label className="form-field"><span>Setup code</span><input type="password" value={token} onChange={(event) => setToken(event.target.value)} required minLength={64} maxLength={64} autoComplete="off" autoCapitalize="none" spellCheck={false} /><small>Copy the one-time code printed by the installer.</small></label>
              <label className="form-field"><span>Business name</span><input value={form.business_name} onChange={change('business_name')} required maxLength={255} autoComplete="organization" /></label>
              <div className="setup-fields"><label className="form-field"><span>Business code</span><input value={form.business_code} onChange={change('business_code')} required pattern="[A-Z0-9][A-Z0-9_-]{0,19}" maxLength={20} spellCheck={false} /></label>
                <label className="form-field"><span>Main branch name</span><input value={form.branch_name} onChange={change('branch_name')} required maxLength={255} /></label></div>
            </> : <>
              <label className="form-field"><span>Your display name</span><input value={form.display_name} onChange={change('display_name')} required maxLength={255} autoComplete="name" /></label>
              <label className="form-field"><span>Username</span><input value={form.username} onChange={change('username')} required minLength={3} maxLength={50} pattern="[a-zA-Z0-9][a-zA-Z0-9_.-]{2,49}" autoComplete="username" autoCapitalize="none" spellCheck={false} /></label>
              <div className="setup-fields"><label className="form-field"><span>Access code</span><input type="password" value={form.access_code} onChange={change('access_code')} required minLength={status.minimum_access_code_length} maxLength={72} autoComplete="new-password" /><small>At least {status.minimum_access_code_length} characters.</small></label>
                <label className="form-field"><span>Confirm access code</span><input type="password" value={form.access_code_confirmation} onChange={change('access_code_confirmation')} required maxLength={72} autoComplete="new-password" /></label></div>
              <p className="setup-note">Keep these credentials private. You can add staff and manager accounts after signing in.</p>
            </>}
            <div className="setup-actions">{step === 2 && <button type="button" className="setup-back" onClick={() => { setStep(1); setError('') }}>Back</button>}<button className="primary-button setup-primary" type="submit">{busy ? 'Creating your workspace…' : step === 1 ? 'Continue' : 'Create business and account'}</button></div>
          </fieldset>
        </form>
      </>}
    </section>
  </main>
}
