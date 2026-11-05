import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import { useAuth } from '../../context/AuthContext'

export default function SubscriptionPage() {
  const { refreshSession } = useAuth()
  const [details, setDetails] = useState(null)
  const [code, setCode] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    try {
      const response = await api.get('/v1/subscription')
      setDetails(response.data)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load subscription.'))
    }
  }, [])

  useEffect(() => {
    const timeout = window.setTimeout(() => { void load() }, 0)
    return () => window.clearTimeout(timeout)
  }, [load])

  async function activate(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const response = await api.post('/v1/subscription/activate', { activation_code: code.trim() })
      setDetails(response.data)
      setCode('')
      await refreshSession()
      setMessage('Your subscription is activated.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to activate subscription.'))
    } finally {
      setSaving(false)
    }
  }

  return <div className="page-stack">
    <section className="page-heading"><h1>Subscription</h1></section>
    {message && <p role="status">{message}</p>}
    {!details ? <p>Loading subscription...</p> : <>
      <section className="content-card">
        <h2>{details.subscription.name} plan</h2>
        <p>{details.subscription.status === 'active' ? 'Your license is active.' : details.subscription.status === 'trial' ? 'You are trying Premium features.' : 'Your subscription is read only. Activate a valid license to continue.'}</p>
        {['trial', 'trial_exhausted'].includes(details.subscription.status) && <p>Premium trial: {details.subscription.trial_used} of {details.subscription.trial_limit} activities used. {details.subscription.trial_remaining} remaining.</p>}
        <p>{details.subscription.status === 'active' ? 'License duration: permanent' : 'Trial is limited by usage rather than a calendar date.'}</p>
        <label className="form-field"><span>Installation ID</span><input readOnly value={details.installation_id} /></label>
        <p>Business code: <strong>{details.business_code}</strong></p>
        <p>License revision: {details.revision}</p>
        <p>Send the installation ID, business code and current revision to your provider to request an activation code.</p>
        <ul>{details.subscription.feature_catalog.map((feature) => <li key={feature.code}>{feature.name}: {feature.included ? 'Included' : 'Requires an upgrade'}</li>)}</ul>
      </section>
      <form className="content-card" onSubmit={activate}>
        <h2>Activate a license</h2>
        <label className="form-field"><span>Activation code</span><textarea rows={5} required maxLength={4096} value={code} onChange={(event) => setCode(event.target.value)} spellCheck={false} autoComplete="off" /></label>
        <p>Paste the code supplied for this installation. Your existing records are preserved when changing plans.</p>
        <button className="primary-button" type="submit" disabled={saving || !code.trim()}>{saving ? 'Activating...' : 'Activate license'}</button>
      </form>
    </>}
  </div>
}
