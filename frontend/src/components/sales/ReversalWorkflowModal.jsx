import SearchableSelect from '../common/SearchableSelect'
import { useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import { formatPeso } from '../../utils/formatters'

function ImpactList({ title, rows = [], moneyLabels = [] }) {
  return (
    <section className="reversal-impact-card">
      <h3>{title}</h3>
      <div className="reversal-impact-list">
        {rows.map((row, index) => {
          const isMoney = moneyLabels.includes(row.label)
          return <div className="reversal-impact-row" key={`${title}-${index}`}><span className="reversal-impact-label">{row.label}</span><span className="reversal-impact-value">{isMoney && typeof row.value === 'number' ? formatPeso(row.value) : String(row.value ?? '—')}</span></div>
        })}
      </div>
    </section>
  )
}

export default function ReversalWorkflowModal({ onClose, onSubmitted }) {
  const [options, setOptions] = useState([])
  const [reasons, setReasons] = useState([])
  const [saleId, setSaleId] = useState('')
  const [preview, setPreview] = useState(null)
  const [reasonCode, setReasonCode] = useState('')
  const [remarks, setRemarks] = useState('')
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    Promise.all([
      api.get('/v1/sales/transactions/reversal-options'),
      api.get('/v1/workflow-reasons', { params: { context: 'SALES_REVERSAL' } }),
    ])
      .then(([optionResponse, reasonResponse]) => {
        setOptions(optionResponse.data.bookings || [])
        setReasons(reasonResponse.data.reasons || [])
      })
      .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load reversal options.')))
  }, [])

  useEffect(() => {
    setPreview(null)
    setMessage('')
    if (!saleId) return
    api.get(`/v1/sales/transactions/${saleId}/reversal-preview`)
      .then((response) => setPreview(response.data))
      .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to preview reversal waterfall.')))
  }, [saleId])

  const selected = useMemo(() => options.find((row) => String(row.sale_id) === String(saleId)), [options, saleId])
  const canContinue = Boolean(saleId && preview && reasonCode && remarks.trim() && !preview?.active_request)

  async function submit() {
    if (!canContinue) return
    setBusy(true)
    setMessage('')
    try {
      await api.post(`/v1/sales/transactions/${saleId}/action-requests`, {
        action_type: 'REVERSAL',
        reason_code: reasonCode,
        remarks: remarks.trim(),
        lines: [],
      })
      setConfirmOpen(false)
      onSubmitted?.('Reversal request submitted for approval. It is now in Pending.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit reversal request.'))
      setConfirmOpen(false)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Modal title="Reverse Booking Transaction" subtitle="Whole-booking reversal with operational and financial waterfall preview" onClose={onClose} wide className="reversal-workflow-modal">
        <div className="reversal-workflow-stack">
          {message ? <div className="form-alert error">{message}</div> : null}

          <section className="reversal-form-card">
            <div className="reversal-form-grid">
              <label className="form-field reversal-booking-field">
                <span>Booking ID *</span>
                <SearchableSelect value={saleId} onChange={(event) => setSaleId(event.target.value)}>
                  <option value="">Select Booking ID</option>
                  {options.map((row) => <option key={row.sale_id} value={row.sale_id}>{row.booking_code} · {row.customer_name || 'Walk-in'} · {row.status}</option>)}
                </SearchableSelect>
              </label>
              <label className="form-field">
                <span>Reason *</span>
                <SearchableSelect value={reasonCode} onChange={(event) => setReasonCode(event.target.value)}>
                  <option value="">Select reason</option>
                  {reasons.map((reason) => <option key={reason.id} value={reason.code}>{reason.name}</option>)}
                </SearchableSelect>
              </label>
            </div>
            <label className="form-field reversal-remarks-field">
              <span>Remarks *</span>
              <textarea rows="4" value={remarks} onChange={(event) => setRemarks(event.target.value)} placeholder="State exactly why the whole Booking transaction must be reversed." />
            </label>
          </section>

          {selected ? <section className="reversal-booking-summary">
            <div><span>Booking</span><span className="reversal-summary-value">{selected.booking_code}</span></div>
            <div><span>Transaction</span><span className="reversal-summary-value">{selected.sale_code}</span></div>
            <div><span>Current Status</span><span className="reversal-summary-value">{selected.status}</span></div>
            <div><span>Effective Amount</span><span className="reversal-summary-value">{formatPeso(selected.amount || 0)}</span></div>
          </section> : null}

          {preview?.active_request ? <div className="form-alert error">This Booking already has active request {preview.active_request.code} ({String(preview.active_request.action_type || '').replaceAll('_', ' ')}). Resolve it first.</div> : null}

          {preview ? <div className="reversal-impact-grid">
            <ImpactList title="Operational Waterfall" rows={preview.summary?.operational || []} moneyLabels={['Service consumption effective cost reversed', 'Commission amount reversed']} />
            <ImpactList title="Financial Waterfall" rows={preview.summary?.financial || []} moneyLabels={['Effective transaction amount becomes', 'Current applied/collected amount becoming Refund Due', 'Current Balance Due cleared']} />
          </div> : null}

          <div className="reversal-control-note">
            <strong>Approval controlled.</strong>
            <span>Submitting does not reverse anything yet. The request returns to Pending. Only approval posts the full waterfall atomically. A Super Admin may manually approve their own request.</span>
          </div>

          <div className="modal-actions reversal-workflow-actions">
            <button className="secondary-button" type="button" onClick={onClose}>Cancel</button>
            <button className="danger-button" type="button" disabled={!canContinue || busy} onClick={() => setConfirmOpen(true)}>Review & Submit Reversal</button>
          </div>
        </div>
      </Modal>

      {confirmOpen ? <Modal title="Confirm Reversal Request" subtitle={selected ? `${selected.booking_code} · ${selected.sale_code}` : 'Booking reversal'} onClose={() => setConfirmOpen(false)} className="reversal-confirm-modal">
        <div className="reversal-confirm-stack">
          <div className="reversal-confirm-warning"><strong>This is a whole-booking reversal request.</strong><p>Approval will move the transaction to Reversed and the Booking to Others, counter-post operational effects, reverse payment applications, and create Refund Due for collected money where applicable.</p></div>
          <div className="reversal-confirm-details"><span>Reason</span><span className="reversal-confirm-value">{reasons.find((reason) => reason.code === reasonCode)?.name || '—'}</span><span>Remarks</span><span className="reversal-confirm-value">{remarks.trim()}</span></div>
          <div className="modal-actions"><button className="secondary-button" type="button" disabled={busy} onClick={() => setConfirmOpen(false)}>Back</button><button className="danger-button" type="button" disabled={busy} onClick={submit}>{busy ? 'Submitting...' : 'Submit for Approval'}</button></div>
        </div>
      </Modal> : null}
    </>
  )
}
