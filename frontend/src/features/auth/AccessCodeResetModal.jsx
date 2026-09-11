import { useState } from 'react'
import { getApiErrorMessage } from '../../api/client'
import { useAuth } from '../../context/AuthContext'

export default function AccessCodeResetModal() {
  const { user, changeAccessCode } = useAuth()
  const [accessCode, setAccessCode] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  if (!user?.force_reset) return null

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')

    if (accessCode.length < 6) {
      setError('Access Code must contain at least 6 characters.')
      return
    }

    if (accessCode !== confirmation) {
      setError('The Access Codes do not match.')
      return
    }

    setSaving(true)
    try {
      await changeAccessCode(accessCode, confirmation)
    } catch (requestError) {
      setError(getApiErrorMessage(requestError, 'Unable to update the Access Code.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="required-reset-backdrop" role="dialog" aria-modal="true" aria-labelledby="reset-title">
      <form className="required-reset-card" onSubmit={handleSubmit}>
        <div className="brand-mark small">Y</div>
        <h2 id="reset-title">Set a New Access Code</h2>
        <p>Create a new Access Code before continuing.</p>

        {error && <div className="form-alert error">{error}</div>}

        <label className="form-field">
          <span>New Access Code</span>
          <input
            type="password"
            value={accessCode}
            onChange={(event) => setAccessCode(event.target.value)}
            autoComplete="new-password"
            minLength={6}
            required
          />
        </label>

        <label className="form-field">
          <span>Confirm Access Code</span>
          <input
            type="password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            autoComplete="new-password"
            minLength={6}
            required
          />
        </label>

        <button className="primary-button" type="submit" disabled={saving}>
          {saving ? 'Saving...' : 'Save New Access Code'}
        </button>
      </form>
    </div>
  )
}
