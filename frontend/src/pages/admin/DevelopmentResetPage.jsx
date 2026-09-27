import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'

function number(value) {
  return Number(value || 0).toLocaleString()
}

function totalRows(rows = {}) {
  return Object.values(rows).reduce((sum, value) => sum + Number(value || 0), 0)
}

function modeLabel(mode) {
  return mode === 'FULL' ? 'Full Reset' : 'Customer Tests Only'
}

export default function DevelopmentResetPage() {
  const [data, setData] = useState(null)
  const [message, setMessage] = useState('')
  const [messageTone, setMessageTone] = useState('error')
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(null)
  const [armed, setArmed] = useState({})
  const [now, setNow] = useState(Date.now())

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/development-reset')
    setData(response.data)
    return response.data
  }, [])

  useEffect(() => {
    load().catch((error) => {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to load Development Reset.'))
    })
  }, [load])

  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(id)
  }, [])

  const fullTotal = useMemo(() => totalRows(data?.full_preview), [data?.full_preview])
  const testTotal = useMemo(() => totalRows(data?.test_preview), [data?.test_preview])

  function openArm(mode) {
    setConfirming({
      action: 'ARM',
      mode,
      access_code: '',
      confirmation_phrase: '',
    })
  }

  function openExecute(mode) {
    const arm = armed[mode]
    if (!arm?.arm_token) {
      setMessageTone('error')
      setMessage('This browser does not hold the arm token. Cancel/re-arm the reset first.')
      return
    }
    setConfirming({
      action: 'EXECUTE',
      mode,
      access_code: '',
      confirmation_phrase: '',
    })
  }

  async function submitConfirmation(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')
    const mode = confirming.mode
    try {
      if (confirming.action === 'ARM') {
        const response = await api.post('/v1/admin/development-reset/arm', {
          mode,
          access_code: confirming.access_code,
          confirmation_phrase: confirming.confirmation_phrase,
        })
        setArmed((current) => ({ ...current, [mode]: response.data }))
        setMessageTone('success')
        setMessage(`${modeLabel(mode)} is armed for ${data?.arm_minutes || 5} minutes. Review the preview, then execute when ready.`)
      } else {
        const arm = armed[mode]
        const response = await api.post('/v1/admin/development-reset/execute', {
          mode,
          arm_token: arm.arm_token,
          access_code: confirming.access_code,
          confirmation_phrase: confirming.confirmation_phrase,
        })
        setArmed((current) => ({ ...current, [mode]: null }))
        setMessageTone('success')
        setMessage(`${response.data.message} ${number(response.data.total_deleted)} rows were deleted.`)
        await load()
      }
      setConfirming(null)
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to complete the Development Reset action.'))
    } finally {
      setBusy(false)
    }
  }

  async function cancelArm(mode) {
    setBusy(true)
    try {
      await api.post('/v1/admin/development-reset/cancel', { mode })
      setArmed((current) => ({ ...current, [mode]: null }))
      setMessageTone('success')
      setMessage(`${modeLabel(mode)} arm cancelled.`)
      await load()
    } catch (error) {
      setMessageTone('error')
      setMessage(getApiErrorMessage(error, 'Unable to cancel the reset arm.'))
    } finally {
      setBusy(false)
    }
  }

  function secondsRemaining(mode) {
    const expires = armed[mode]?.expires_at
    if (!expires) return null
    return Math.max(0, Math.ceil((new Date(expires).getTime() - now) / 1000))
  }

  function previewTable(rows) {
    const entries = Object.entries(rows || {}).filter(([, count]) => Number(count) > 0)
    if (!entries.length) return <div className="empty-state">No rows are currently targeted.</div>
    return <div className="table-wrap devreset-preview-table"><table className="data-table"><thead><tr><th>Target</th><th>Rows</th></tr></thead><tbody>{entries.map(([table, count]) => <tr key={table}><td>{table}</td><td><strong>{number(count)}</strong></td></tr>)}</tbody></table></div>
  }

  const phrase = confirming ? data?.confirmation_phrases?.[confirming.mode] : ''
  const currentRemaining = confirming ? secondsRemaining(confirming.mode) : null

  return <div className="page-stack devreset-page">
    <div className="page-heading"><div><p className="eyebrow">Administration</p><h1 className="live-page-title">Development Reset <PageRefreshButton label="Refresh Development Reset" onRefresh={load} /></h1><p>Super Admin development tools for clearing test/transaction data without rebuilding the application.</p></div></div>

    {message ? <div className={`form-alert ${messageTone}`}>{message}</div> : null}

    {data && !data.allowed ? <div className="form-alert error">Development Reset is disabled because APP_ENV is <strong>{data.environment}</strong>. It is available only in local/development environments.</div> : null}

    <section className="content-card devreset-environment-card">
      <div className="section-heading"><div><h2>Current Development Target</h2><p>Every reset is bound to this environment and database when armed.</p></div></div>
      <div className="devreset-meta-grid">
        <div><span>Environment</span><strong>{data?.environment || '—'}</strong></div>
        <div><span>Database</span><strong>{data?.database || '—'}</strong></div>
        <div><span>Super Admin</span><strong>{data?.user?.display_name || data?.user?.username || '—'}</strong></div>
        <div><span>Arm Window</span><strong>{data?.arm_minutes || 5} minutes</strong></div>
      </div>
    </section>

    <div className="devreset-option-grid">
      <section className="content-card devreset-card">
        <div className="section-heading"><div><h2>Customer Tests Only</h2></div></div>
        <div className="devreset-total"><span>Rows currently targeted</span><strong>{number(testTotal)}</strong></div>
        {(data?.test_customers || []).length ? <div className="devreset-customer-list"><span>Test Customers</span><strong>{data.test_customers.map((row) => `${row.code} · ${row.name}`).join(', ')}</strong></div> : <div className="empty-state">No customers are marked as Test Customer.</div>}
        {previewTable(data?.test_preview)}
        <div className="modal-actions devreset-actions">
          {armed.TEST_ONLY?.arm_token ? <><span className="devreset-armed-text">Armed · {secondsRemaining('TEST_ONLY')}s remaining</span><button className="secondary-button" type="button" disabled={busy} onClick={() => cancelArm('TEST_ONLY')}>Cancel Arm</button><button className="danger-button" type="button" disabled={busy || secondsRemaining('TEST_ONLY') <= 0} onClick={() => openExecute('TEST_ONLY')}>Execute Test Reset</button></> : <button className="primary-button" type="button" disabled={busy || !data?.allowed || !(data?.test_customers || []).length} onClick={() => openArm('TEST_ONLY')}>Arm Customer Test Reset</button>}
        </div>
      </section>

      <section className="content-card devreset-card danger-zone-card">
        <div className="section-heading"><div><h2>Full Reset</h2></div></div>
        <div className="devreset-total"><span>Rows currently targeted</span><strong>{number(fullTotal)}</strong></div>
        {previewTable(data?.full_preview)}
        <div className="modal-actions devreset-actions">
          {armed.FULL?.arm_token ? <><span className="devreset-armed-text">Armed · {secondsRemaining('FULL')}s remaining</span><button className="secondary-button" type="button" disabled={busy} onClick={() => cancelArm('FULL')}>Cancel Arm</button><button className="danger-button" type="button" disabled={busy || secondsRemaining('FULL') <= 0} onClick={() => openExecute('FULL')}>Execute Full Reset</button></> : <button className="danger-button" type="button" disabled={busy || !data?.allowed} onClick={() => openArm('FULL')}>Arm Full Reset</button>}
        </div>
      </section>
    </div>

    {confirming ? <Modal title={`${confirming.action === 'ARM' ? 'Arm' : 'Execute'} ${modeLabel(confirming.mode)}`} subtitle={confirming.action === 'ARM' ? `This creates a ${data?.arm_minutes || 5}-minute execution window.` : `The armed reset will run immediately. ${currentRemaining !== null ? `${currentRemaining}s remain.` : ''}`} onClose={() => !busy && setConfirming(null)}>
      <form onSubmit={submitConfirmation}>
        <div className="form-alert error">Type the exact confirmation phrase and enter your Super Admin Access Code.</div>
        <label className="form-field"><span>Confirmation Phrase</span><input autoComplete="off" required value={confirming.confirmation_phrase} placeholder={phrase} onChange={(event) => setConfirming((current) => ({ ...current, confirmation_phrase: event.target.value }))} /><small>Required: <strong>{phrase}</strong></small></label>
        <label className="form-field"><span>Super Admin Access Code</span><input type="password" autoComplete="current-password" required value={confirming.access_code} onChange={(event) => setConfirming((current) => ({ ...current, access_code: event.target.value }))} /></label>
        <div className="modal-actions"><button className="secondary-button" type="button" disabled={busy} onClick={() => setConfirming(null)}>Cancel</button><button className="danger-button" type="submit" disabled={busy || confirming.confirmation_phrase !== phrase}>{busy ? 'Processing...' : confirming.action === 'ARM' ? 'Arm Reset' : 'Execute Reset'}</button></div>
      </form>
    </Modal> : null}
  </div>
}
