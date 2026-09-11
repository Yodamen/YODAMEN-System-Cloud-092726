import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { getApiErrorMessage } from '../api/client'
import { useAuth } from '../context/AuthContext'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [username, setUsername] = useState('')
  const [accessCode, setAccessCode] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

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
    <main className="login-page">
      <section className="login-brand-panel">
        <div className="brand-mark large">Y</div>
        <div>
          <span className="eyebrow">YODAMEN</span>
          <h1>Management System</h1>
          <p>Operations, sales, people, and management controls in one workspace.</p>
        </div>
      </section>

      <section className="login-form-panel">
        <form className="login-card" onSubmit={handleSubmit}>
          <span className="eyebrow gold">Secure Access</span>
          <h2>Sign in</h2>
          <p className="muted">Use your YODAMEN username and Access Code.</p>

          {error && <div className="form-alert error">{error}</div>}

          <label className="form-field">
            <span>Username</span>
            <input
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              autoComplete="username"
              required
              autoFocus
            />
          </label>

          <label className="form-field">
            <span>Access Code</span>
            <input
              type="password"
              value={accessCode}
              onChange={(event) => setAccessCode(event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>

          <button className="primary-button" type="submit" disabled={submitting}>
            {submitting ? 'Signing in...' : 'Sign In'}
          </button>
        </form>
      </section>
    </main>
  )
}
