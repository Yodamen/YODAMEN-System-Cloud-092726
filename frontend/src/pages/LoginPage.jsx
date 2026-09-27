import { useEffect, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { api, getApiErrorMessage } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { brandingCssVariables } from '../utils/branding'
import yodamenLogo from '../assets/yodamen-logo-black.png'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [username, setUsername] = useState('')
  const [accessCode, setAccessCode] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [branding, setBranding] = useState(null)

  useEffect(() => {
    api.get('/v1/public/branding').then((response) => setBranding(response.data.branding || null)).catch(() => {})
  }, [])

  if (user) {
    return <Navigate to="/" replace />
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setSubmitting(true)

    try {
      await login(username, accessCode)
      const destination = location.state?.from?.pathname || '/'
      navigate(destination, { replace: true })
    } catch (requestError) {
      setError(getApiErrorMessage(requestError, 'Unable to sign in.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page login-page-centered" style={brandingCssVariables(branding)}>
      <form className="login-card login-card-dark" onSubmit={handleSubmit}>
        <div className="login-logo-wrap">
          <img className="login-yodamen-logo" src={branding?.logo_url || yodamenLogo} alt={branding?.business_display_name || 'YODAMEN Grooming Lounge'} />
        </div>

        {error && <div className="form-alert error login-alert">{error}</div>}

        <label className="form-field login-field">
          <span>Username</span>
          <input value={username} onChange={(event) => setUsername(event.target.value)} autoComplete="username" required autoFocus />
        </label>

        <label className="form-field login-field">
          <span>Access Code</span>
          <input type="password" value={accessCode} onChange={(event) => setAccessCode(event.target.value)} autoComplete="current-password" required />
        </label>

        <button className="primary-button login-submit-button" type="submit" disabled={submitting}>
          {submitting ? 'Signing in...' : 'Sign In'}
        </button>

        <button className="login-reset-link" type="button" title="Access Code recovery is handled by an administrator." onClick={() => setError('Access Code recovery is handled by an administrator.')}>
          Reset Access Code
        </button>

        <div className="login-system-label">{branding?.login_label || 'Operating System'}</div>
      </form>
    </main>
  )
}
