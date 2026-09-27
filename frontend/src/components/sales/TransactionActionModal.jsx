import SearchableSelect from '../common/SearchableSelect'
import WorkflowReasonSelect from '../common/WorkflowReasonSelect'
import { useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../common/Modal'
import ActionConfirmationModal from '../common/ActionConfirmationModal'
import SupportingDocumentField from '../common/SupportingDocumentField'
import { formatPeso } from '../../utils/formatters'
import ServiceCreditClaimSection from '../operations/ServiceCreditClaimSection'
import { uploadSupportingDocuments } from '../../utils/supportingDocuments'

const ACTION_LABELS = {
  CORRECTION: 'Correction',
  REFUND: 'Refund',
  REVERSAL: 'Reversal',
}

function statusLabel(value) {
  return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase())
}

function lineName(line) {
  return line.item_name || line.item_name_snapshot || 'Transaction Line'
}

function terminalLine(line) {
  return ['REMOVED', 'REVERSED'].includes(String(line?.correction_status || 'ACTIVE').toUpperCase())
}

function lineAvailableForAction(line, actionType) {
  if (actionType === 'CORRECTION') return !terminalLine(line)
  if (actionType === 'REFUND') {
    const due = Number(line?.refund_due_amount || 0)
    const effective = Number(line?.effective_net_line_total ?? line?.net_line_total ?? line?.line_total ?? 0)
    return terminalLine(line) ? due > 0.009 : due + effective > 0.009
  }
  return true
}


function reviewTypeLabel(value) {
  const type = String(value || '').toUpperCase()
  if (type === 'SERVICE_CREDIT_USE' || type === 'SERVICE_CREDIT') return 'Service Credit'
  if (type === 'PRODUCT') return 'Product'
  if (type === 'PACKAGE') return 'Package'
  return type ? statusLabel(type) : 'Service'
}

function reviewPersonnelName(context, id, fallback = null) {
  if (!id) return fallback || 'Unassigned'
  const pools = [...(context?.personnel || []), ...(context?.retail_personnel || [])]
  return pools.find((person) => Number(person.id) === Number(id))?.name || fallback || 'Unassigned'
}

function reviewLineFromSale(line) {
  return {
    key: `sale-${line.id}`,
    sale_item_id: line.id,
    name: line.item_name || line.item_name_snapshot || 'Transaction Line',
    type: reviewTypeLabel(line.item_type),
    quantity: Number(line.quantity || 0),
    assigned_personnel_id: line.assigned_personnel_id || null,
    staff: line.assigned_personnel_name || line.assigned_personnel_name_snapshot || 'Unassigned',
    unit_price: Number(line.unit_price || 0),
    status: 'ORIGINAL',
  }
}

export default function TransactionActionModal({
  context,
  initialAction,
  preselectedItemId,
  currentUser,
  canApprove,
  reviewMode = null,
  onClose,
  onChanged,
}) {
  const activeRequest = context?.active_request || null
  const sale = context?.sale || {}
  const [actionType, setActionType] = useState(initialAction || 'CORRECTION')
  const [reasonCode, setReasonCode] = useState('')
  const [remarks, setRemarks] = useState('')
  const [refundMethod, setRefundMethod] = useState(() => context?.payment_methods?.[0]?.name || 'Cash')
  const [refundReference, setRefundReference] = useState('')
  const [managerRemarks, setManagerRemarks] = useState('')
  const [reviewReasonReferenceId, setReviewReasonReferenceId] = useState('')
  const [supportingFiles, setSupportingFiles] = useState([])
  const [reviewConfirmation, setReviewConfirmation] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [serviceDrafts, setServiceDrafts] = useState([{ service_id: '', quantity: 1, assigned_personnel_id: '', unit_price: 0, service_credit_id: '' }])
  const [retailDrafts, setRetailDrafts] = useState([])
  const [selected, setSelected] = useState(() => {
    const next = {}
    ;(sale.items || []).forEach((line) => {
      next[line.id] = preselectedItemId ? Number(line.id) === Number(preselectedItemId) : false
    })
    return next
  })
  const [edits, setEdits] = useState(() => {
    const next = {}
    ;(sale.items || []).forEach((line) => {
      next[line.id] = {
        line_action: 'EDIT',
        quantity: Number(line.quantity || 1),
        assigned_personnel_id: line.assigned_personnel_id || '',
        unit_price: Number(line.unit_price || 0),
        refund_quantity: Number(line.quantity || 0),
        refund_amount: Number(line.refund_due_amount || 0) + Number(line.effective_net_line_total ?? line.net_line_total ?? line.line_total ?? 0),
        return_to_stock: false,
      }
    })
    return next
  })

  const reasons = useMemo(() => context?.reasons?.[actionType] || {}, [context, actionType])
  const selectedLines = useMemo(
    () => (sale.items || []).filter((line) => selected[line.id] && lineAvailableForAction(line, actionType)),
    [sale.items, selected, actionType],
  )
  const correctionChangedLines = useMemo(
    () => (sale.items || []).filter((line) => {
      if (!lineAvailableForAction(line, 'CORRECTION')) return false
      const edit = edits[line.id] || {}
      if (String(edit.line_action || 'EDIT').toUpperCase() === 'REMOVE') return true

      const originalQuantity = Number(line.quantity || 1)
      const editedQuantity = Number(edit.quantity || 1)
      const originalPersonnel = line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null
      const editedPersonnel = edit.assigned_personnel_id ? Number(edit.assigned_personnel_id) : null
      const originalUnitPrice = Number(line.unit_price || 0)
      const editedUnitPrice = Number(edit.unit_price || 0)

      return editedQuantity !== originalQuantity
        || editedPersonnel !== originalPersonnel
        || Math.abs(editedUnitPrice - originalUnitPrice) > 0.0001
    }),
    [sale.items, edits],
  )


  const reviewOriginalLines = useMemo(
    () => (sale.items || []).map(reviewLineFromSale),
    [sale.items],
  )

  const reviewAdjustedLines = useMemo(() => {
    if (!activeRequest || activeRequest.action_type !== 'CORRECTION') return reviewOriginalLines

    const requestsByItem = new Map(
      (activeRequest.lines || [])
        .filter((line) => line.sale_item_id)
        .map((line) => [Number(line.sale_item_id), line]),
    )

    const adjusted = []
    reviewOriginalLines.forEach((original) => {
      const requestLine = requestsByItem.get(Number(original.sale_item_id))
      if (!requestLine) {
        adjusted.push({ ...original, key: `adjusted-${original.sale_item_id}`, status: 'UNCHANGED' })
        return
      }

      const action = String(requestLine.line_action || '').toUpperCase()
      if (action === 'REMOVE') return

      const proposed = requestLine.proposed_snapshot || {}
      adjusted.push({
        ...original,
        key: `adjusted-${original.sale_item_id}`,
        quantity: Number(proposed.quantity ?? original.quantity),
        assigned_personnel_id: proposed.assigned_personnel_id ?? original.assigned_personnel_id,
        staff: reviewPersonnelName(
          context,
          proposed.assigned_personnel_id ?? original.assigned_personnel_id,
          original.staff,
        ),
        unit_price: Number(proposed.unit_price ?? original.unit_price),
        status: 'EDITED',
      })
    })

    ;(activeRequest.lines || [])
      .filter((line) => String(line.line_action || '').toUpperCase() === 'ADD')
      .forEach((line) => {
        const proposed = line.proposed_snapshot || {}
        const itemKind = proposed.item_kind || (proposed.retail_product_id ? 'PRODUCT' : proposed.service_credit_id ? 'SERVICE_CREDIT' : 'SERVICE')
        adjusted.push({
          key: `added-${line.id}`,
          sale_item_id: null,
          name: line.item_name || proposed.service_name || proposed.product_name || 'Added Line',
          type: reviewTypeLabel(itemKind),
          quantity: Number(proposed.quantity || 1),
          assigned_personnel_id: proposed.assigned_personnel_id || null,
          staff: proposed.assigned_personnel_name || reviewPersonnelName(context, proposed.assigned_personnel_id),
          unit_price: Number(proposed.unit_price || 0),
          status: 'ADDED',
        })
      })

    return adjusted
  }, [activeRequest, context, reviewOriginalLines])

  function updateEdit(id, patch) {
    setEdits((current) => ({ ...current, [id]: { ...current[id], ...patch } }))
  }

  const adjustedServiceCredits = useMemo(() => {
    const usage = {}
    serviceDrafts.forEach((line) => { if (line.service_credit_id) usage[line.service_credit_id] = (usage[line.service_credit_id] || 0) + 1 })
    return (context?.service_credits || []).map((credit) => ({ ...credit, available_sessions: Math.max(0, Number(credit.available_sessions || 0) - (usage[credit.id] || 0)) }))
  }, [context?.service_credits, serviceDrafts])

  function updateServiceDraft(index, patch) {
    setServiceDrafts((current) => current.map((row, rowIndex) => {
      if (rowIndex !== index) return row
      const next = { ...row, ...patch }
      if (patch.service_id !== undefined) {
        const service = (context?.services || []).find((option) => String(option.id) === String(patch.service_id))
        next.unit_price = service ? Number(service.current_price || 0) : 0
      }
      if (patch.service_credit_id) { next.unit_price = 0; next.quantity = 1 }
      return next
    }))
  }

  function addServiceDraft() { setServiceDrafts((current) => [...current, { service_id: '', quantity: 1, assigned_personnel_id: '', unit_price: 0, service_credit_id: '' }]) }
  function removeServiceDraft(index) { setServiceDrafts((current) => current.length <= 1 ? [{ service_id: '', quantity: 1, assigned_personnel_id: '', unit_price: 0, service_credit_id: '' }] : current.filter((_, rowIndex) => rowIndex !== index)) }
  function addRetailDraft() { setRetailDrafts((current) => [...current, { retail_product_id: '', quantity: 1, assigned_personnel_id: '', unit_price: 0 }]) }
  function updateRetailDraft(index, patch) {
    setRetailDrafts((current) => current.map((row, rowIndex) => {
      if (rowIndex !== index) return row
      const next = { ...row, ...patch }
      if (patch.retail_product_id !== undefined) {
        const product = (context?.retail_products || []).find((option) => String(option.id) === String(patch.retail_product_id))
        if (product) next.unit_price = Number(product.selling_price || 0)
      }
      return next
    }))
  }
  function removeRetailDraft(index) { setRetailDrafts((current) => current.filter((_, rowIndex) => rowIndex !== index)) }

  async function submitRequest() {
    setMessage('')
    if (!reasonCode) return setMessage('Select a reason.')
    if (!remarks.trim()) return setMessage('Remarks are required.')


    const existingSourceLines = actionType === 'CORRECTION' ? correctionChangedLines : selectedLines
    const existingLines = actionType === 'REVERSAL' ? [] : existingSourceLines.map((line) => {
      const edit = edits[line.id] || {}
      if (actionType === 'REFUND') {
        const correctionDue = Number(line.refund_due_amount || 0)
        const economicValue = terminalLine(line) ? 0 : Number(line.effective_net_line_total ?? line.net_line_total ?? line.line_total ?? 0)
        return {
          sale_item_id: line.id,
          line_action: 'REFUND',
          refund_quantity: terminalLine(line) ? 0 : Number(line.quantity || 0),
          refund_amount: Math.max(0, correctionDue + economicValue),
          return_to_stock: String(line.item_type || '').toUpperCase() === 'PRODUCT' ? Boolean(edit.return_to_stock) : null,
        }
      }
      return {
        sale_item_id: line.id,
        line_action: edit.line_action,
        quantity: Number(edit.quantity || 1),
        assigned_personnel_id: edit.assigned_personnel_id ? Number(edit.assigned_personnel_id) : null,
        unit_price: Number(edit.unit_price || 0),
      }
    })
    const serviceAdditions = actionType === 'CORRECTION' ? serviceDrafts.filter((line) => line.service_id).map((line) => ({
      sale_item_id: null,
      line_action: 'ADD',
      item_kind: line.service_credit_id ? 'SERVICE_CREDIT' : 'SERVICE',
      service_id: Number(line.service_id),
      service_credit_id: line.service_credit_id ? Number(line.service_credit_id) : null,
      quantity: line.service_credit_id ? 1 : Number(line.quantity || 1),
      assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null,
      unit_price: line.service_credit_id ? 0 : Number(line.unit_price || 0),
    })) : []
    const retailAdditions = actionType === 'CORRECTION' ? retailDrafts.filter((line) => line.retail_product_id).map((line) => ({
      sale_item_id: null,
      line_action: 'ADD',
      item_kind: 'PRODUCT',
      retail_product_id: Number(line.retail_product_id),
      quantity: Number(line.quantity || 1),
      assigned_personnel_id: line.assigned_personnel_id ? Number(line.assigned_personnel_id) : null,
      unit_price: Number(line.unit_price || 0),
    })) : []
    const lines = [...existingLines, ...serviceAdditions, ...retailAdditions]
    if (actionType !== 'REVERSAL' && !lines.length) return setMessage(actionType === 'CORRECTION' ? 'Make at least one transaction change or add a line.' : 'Select at least one transaction line.')

    setBusy(true)
    try {
      const attachmentTokens = await uploadSupportingDocuments(supportingFiles, 'sales', 'TRANSACTION_ACTION_SUPPORT')
      await api.post(`/v1/sales/transactions/${sale.id}/action-requests`, {
        action_type: actionType,
        reason_code: reasonCode,
        remarks: remarks.trim(),
        refund_method: actionType === 'REFUND' ? refundMethod : null,
        refund_reference: actionType === 'REFUND' ? refundReference.trim() || null : null,
        attachment_tokens: attachmentTokens,
        lines,
      })
      await onChanged?.('Request submitted for Manager approval.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to submit transaction action request.'))
    } finally {
      setBusy(false)
    }
  }

  function review(decision) {
    setMessage('')
    if (decision === 'reject' && !reviewReasonReferenceId) return setMessage('Select a rejection category.')
    if (decision === 'reject' && !managerRemarks.trim()) return setMessage('Manager remarks are required when rejecting.')
    setReviewConfirmation(decision)
  }

  async function confirmReview() {
    const decision = reviewConfirmation
    if (!decision || !activeRequest) return
    setBusy(true)
    try {
      await api.post(`/v1/sales/transaction-action-requests/${activeRequest.id}/${decision}`, {
        manager_remarks: managerRemarks.trim() || null,
        ...(decision === 'reject' ? { review_reason_reference_id: Number(reviewReasonReferenceId) } : {}),
      })
      setReviewConfirmation(null)
      await onChanged?.(decision === 'approve' ? 'Request approved and posted.' : 'Request rejected.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, `Unable to ${decision} request.`))
    } finally {
      setBusy(false)
    }
  }

  if (activeRequest) {
    const requesterIsCurrentUser = Number(activeRequest.requested_by?.id) === Number(currentUser?.id)
    const superAdminSelfApproval = requesterIsCurrentUser && Boolean(currentUser?.role?.is_super_admin)
    const canReviewRequest = activeRequest.status === 'PENDING_MANAGER_APPROVAL'
      && canApprove
      && (!requesterIsCurrentUser || superAdminSelfApproval)

    return (
      <>
      <Modal
        title={`${ACTION_LABELS[activeRequest.action_type] || activeRequest.action_type} Request`}
        subtitle={`${activeRequest.code} · ${sale.code || ''}`}
        onClose={onClose}
        wide
        className="transaction-action-modal transaction-action-review-modal"
      >
        <div className="transaction-action-review">
          {message ? <div className="form-message error">{message}</div> : null}

          <section className="transaction-action-review-summary" aria-label="Request summary">
            <div className="transaction-action-review-summary-item">
              <span>Status</span>
              <strong className="transaction-action-review-status">{statusLabel(activeRequest.status)}</strong>
            </div>
            <div className="transaction-action-review-summary-item">
              <span>Reason</span>
              <strong>{activeRequest.reason_label}</strong>
            </div>
            <div className="transaction-action-review-summary-item">
              <span>Requested By</span>
              <strong>{activeRequest.requested_by?.name || '—'}</strong>
            </div>
          </section>

          <section className="transaction-action-card transaction-action-review-card">
            <div className="transaction-action-section-heading transaction-action-review-heading">
              <div>
                <h3>Request Details</h3>
                <p>Review the original transaction lines against the requested correction before posting.</p>
              </div>
            </div>

            <div className="transaction-action-review-remarks">
              <span>Request Remarks</span>
              <p>{activeRequest.remarks || '—'}</p>
            </div>

            {(activeRequest.lines || []).length ? (
              <>
                <div className="transaction-action-review-subheading">Adjustment Summary</div>
                <div className="table-wrap transaction-action-review-table-wrap">
                  <table className="data-table transaction-action-review-table">
                    <thead>
                      <tr>
                        <th>Line</th>
                        <th>Action</th>
                        <th>Original</th>
                        <th>Requested</th>
                      </tr>
                    </thead>
                    <tbody>{activeRequest.lines.map((line) => {
                      const original = line.original_snapshot || {}
                      const proposed = line.proposed_snapshot || {}
                      const originalStaff = reviewPersonnelName(context, original.assigned_personnel_id, original.assigned_personnel_name)
                      const requestedStaff = reviewPersonnelName(context, proposed.assigned_personnel_id, originalStaff)
                      return (
                        <tr key={line.id}>
                          <td><strong>{line.item_name || 'Line'}</strong></td>
                          <td><span className={`transaction-action-review-action action-${String(line.line_action || '').toLowerCase()}`}>{statusLabel(line.line_action)}</span></td>
                          <td>{line.line_action === 'ADD' ? '—' : <span className="transaction-action-review-line-detail">Qty {original.quantity ?? '—'} · {originalStaff} · {formatPeso(original.unit_price || 0)}</span>}</td>
                          <td>{activeRequest.action_type === 'REFUND'
                            ? `${formatPeso(line.refund_amount)} · Qty ${line.refund_quantity}`
                            : line.line_action === 'REMOVE'
                              ? 'Remove line'
                              : <span className="transaction-action-review-line-detail">Qty {proposed.quantity ?? original.quantity ?? 1} · {requestedStaff} · {formatPeso(proposed.unit_price ?? original.unit_price ?? 0)}</span>}</td>
                        </tr>
                      )
                    })}</tbody>
                  </table>
                </div>

                {activeRequest.action_type === 'CORRECTION' ? (
                  <div className="transaction-action-review-comparison">
                    <section className="transaction-action-review-line-set">
                      <div className="transaction-action-review-line-set-header">
                        <div>
                          <span>Before Approval</span>
                          <h4>Original Transaction Lines</h4>
                        </div>
                        <small>{reviewOriginalLines.length} line{reviewOriginalLines.length === 1 ? '' : 's'}</small>
                      </div>
                      <div className="table-wrap">
                        <table className="data-table transaction-action-review-line-set-table">
                          <thead><tr><th>Line</th><th>Type</th><th>Qty</th><th>Staff</th><th className="money-column">Unit Price</th></tr></thead>
                          <tbody>{reviewOriginalLines.map((line) => (
                            <tr key={line.key}>
                              <td>{line.name}</td>
                              <td>{line.type}</td>
                              <td>{line.quantity}</td>
                              <td>{line.staff}</td>
                              <td className="money-cell">{formatPeso(line.unit_price)}</td>
                            </tr>
                          ))}</tbody>
                        </table>
                      </div>
                    </section>

                    <section className="transaction-action-review-line-set is-adjusted">
                      <div className="transaction-action-review-line-set-header">
                        <div>
                          <span>If Approved</span>
                          <h4>Adjusted Transaction Lines</h4>
                        </div>
                        <small>{reviewAdjustedLines.length} line{reviewAdjustedLines.length === 1 ? '' : 's'}</small>
                      </div>
                      <div className="table-wrap">
                        <table className="data-table transaction-action-review-line-set-table">
                          <thead><tr><th>Line</th><th>Result</th><th>Qty</th><th>Staff</th><th className="money-column">Unit Price</th></tr></thead>
                          <tbody>{reviewAdjustedLines.map((line) => (
                            <tr key={line.key} className={`is-${String(line.status || '').toLowerCase()}`}>
                              <td>{line.name}<span className="table-subline">{line.type}</span></td>
                              <td><span className={`transaction-action-review-result result-${String(line.status || '').toLowerCase()}`}>{statusLabel(line.status)}</span></td>
                              <td>{line.quantity}</td>
                              <td>{line.staff}</td>
                              <td className="money-cell">{formatPeso(line.unit_price)}</td>
                            </tr>
                          ))}</tbody>
                        </table>
                      </div>
                    </section>
                  </div>
                ) : null}
              </>
            ) : <div className="empty-state transaction-action-review-empty">Whole transaction reversal.</div>}

            {activeRequest.action_type === 'REFUND' ? (
              <div className="transaction-action-review-refund-summary">
                <span>Requested Refund</span>
                <strong>{formatPeso(activeRequest.requested_refund_amount || 0)}</strong>
                <small>{activeRequest.refund_method || '—'}</small>
              </div>
            ) : null}
          </section>

          <div className="transaction-action-review-notices">
            {superAdminSelfApproval ? (
              <div className="transaction-action-review-notice is-super-admin">
                <strong>Super Admin self-approval</strong>
                <p>You submitted this request and may manually approve or reject it. Approval is not automatic and remains fully audited.</p>
              </div>
            ) : null}
            <div className="transaction-action-review-notice">
              <strong>Atomic posting control</strong>
              <p>Approval posts the action across all affected modules as one transaction. If any downstream effect fails, nothing is posted.</p>
            </div>
          </div>

          {canReviewRequest ? (
            <section className="transaction-action-card transaction-action-review-decision-card">
              <div className="transaction-action-section-heading transaction-action-review-heading">
                <div>
                  <h3>Approval Decision</h3>
                  <p>Add a note when needed. Manager remarks are required only when rejecting the request.</p>
                </div>
              </div>
              <WorkflowReasonSelect
                context="APPROVAL_REJECTION"
                label="Rejection Category"
                value={reviewReasonReferenceId}
                onChange={(event) => setReviewReasonReferenceId(event.target.value)}
                required={false}
                className="transaction-action-review-manager-remarks"
              />
              <label className="form-field transaction-action-review-manager-remarks">
                <span>Manager Remarks</span>
                <textarea
                  rows="3"
                  value={managerRemarks}
                  onChange={(event) => setManagerRemarks(event.target.value)}
                  placeholder="Optional for approval; required for rejection."
                />
              </label>
            </section>
          ) : null}

          <div className="modal-actions transaction-action-review-actions">
            <button className="secondary-button" type="button" onClick={onClose}>Close</button>
            {canReviewRequest && reviewMode !== 'approve' ? <button className="secondary-button" type="button" disabled={busy} onClick={() => review('reject')}>Reject</button> : null}
            {canReviewRequest && reviewMode !== 'reject' ? <button className="primary-button" type="button" disabled={busy} onClick={() => review('approve')}>Approve & Post</button> : null}
          </div>
        </div>
      </Modal>
      {reviewConfirmation ? <ActionConfirmationModal
        title={reviewConfirmation === 'approve' ? 'Approve Transaction Request' : 'Reject Transaction Request'}
        subtitle={activeRequest.code}
        message={reviewConfirmation === 'approve'
          ? 'Approval will post all operational and financial effects as one controlled transaction.'
          : 'The request will be rejected and no transaction effects will be posted.'}
        details={[
          { label: 'Transaction', value: sale.code || '—' },
          { label: 'Action', value: ACTION_LABELS[activeRequest.action_type] || statusLabel(activeRequest.action_type) },
          { label: 'Reason', value: activeRequest.reason_label || '—' },
        ]}
        confirmLabel={reviewConfirmation === 'approve' ? 'Approve & Post' : 'Reject Request'}
        danger={reviewConfirmation === 'reject'}
        busy={busy}
        onCancel={() => setReviewConfirmation(null)}
        onConfirm={confirmReview}
      /> : null}
      </>
    )
  }

  return (
    <Modal
      title={ACTION_LABELS[actionType]}
      subtitle={`${sale.code || 'Transaction'} · Manager approval required`}
      onClose={onClose}
      wide
      className="transaction-action-modal"
    >
      <div className="transaction-action-form">
        {message ? <div className="form-message error">{message}</div> : null}

        <section className="transaction-action-card transaction-action-setup">
          <div className="transaction-action-field-grid">
            <label className="form-field">
              <span>Action</span>
              <div className="transaction-action-locked-value">Correction</div>
            </label>
            <label className="form-field">
              <span>Reason</span>
              <SearchableSelect value={reasonCode} onChange={(event) => setReasonCode(event.target.value)}>
                <option value="">Select reason</option>
                {Object.entries(reasons).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
              </SearchableSelect>
            </label>
          </div>
        </section>

        {actionType !== 'REVERSAL' ? (
          <section className="transaction-action-card transaction-action-lines-card">
            <div className="transaction-action-section-heading">
              <div>
                <h3>Correction Form</h3>
                <p>Use the same booking structure for missed services, service credit claims, and retail purchases. Existing transaction lines can still be edited or removed below.</p>
              </div>
            </div>
            {actionType === 'CORRECTION' && sale.visit_id ? <div className="transaction-action-booking-builder">
              <section className="section-box">
                <div className="section-box-header"><div><h2>Services</h2><p>Add omitted completed services.</p></div><button className="secondary-button" type="button" onClick={addServiceDraft}>Add Service</button></div>
                {serviceDrafts.map((line, index) => <div key={`service-${index}`} className="line-editor-card"><div className="line-editor-grid">
                  <label className="form-field"><span>Service</span><SearchableSelect value={line.service_id} onChange={(event) => updateServiceDraft(index, { service_id: event.target.value, service_credit_id: '' })}><option value="">Select service</option>{(context?.services || []).map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</SearchableSelect></label>
                  <label className="form-field"><span>Qty</span><input type="number" min="1" step="1" value={line.service_credit_id ? 1 : line.quantity} disabled={Boolean(line.service_credit_id)} onChange={(event) => updateServiceDraft(index, { quantity: event.target.value })} /></label>
                  <label className="form-field"><span>Staff</span><SearchableSelect value={line.assigned_personnel_id} onChange={(event) => updateServiceDraft(index, { assigned_personnel_id: event.target.value })}><option value="">Unassigned</option>{(context?.personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</SearchableSelect></label>
                  <label className="form-field"><span>Unit Price</span><input type="number" min="0" step="0.01" value={line.service_credit_id ? 0 : line.unit_price} disabled={Boolean(line.service_credit_id)} onChange={(event) => updateServiceDraft(index, { unit_price: event.target.value })} /></label>
                </div>{index > 0 ? <button className="link-button danger-link" type="button" onClick={() => removeServiceDraft(index)}>Remove</button> : null}</div>)}
              </section>
              <ServiceCreditClaimSection
                customerSelected={Boolean(sale.customer_id)}
                credits={adjustedServiceCredits}
                selectedLines={serviceDrafts.filter((line) => line.service_credit_id)}
                personnel={context?.personnel || []}
                services={context?.services || []}
                onAdd={({ credit, sessions, assignedPersonnelId }) => {
                  const qty = Math.max(1, Number(sessions || 1))
                  setServiceDrafts((current) => [...current, ...Array.from({ length: qty }, () => ({ service_id: String(credit.service_id), quantity: 1, assigned_personnel_id: assignedPersonnelId || '', unit_price: 0, service_credit_id: String(credit.id) }))])
                }}
                onRemove={(serviceCreditId, assignedPersonnelId) => setServiceDrafts((current) => current.filter((line) => !(String(line.service_credit_id || '') === String(serviceCreditId) && String(line.assigned_personnel_id || '') === String(assignedPersonnelId || ''))))}
              />
              <section className="section-box">
                <div className="section-box-header"><div><h2>Retail Purchases</h2><p>Add omitted retail purchases.</p></div><button className="secondary-button" type="button" onClick={addRetailDraft}>+ Add Retail</button></div>
                {retailDrafts.length ? retailDrafts.map((line, index) => <div key={`retail-${index}`} className="line-editor-card"><div className="line-editor-grid retail-grid">
                  <label className="form-field"><span>Product</span><SearchableSelect value={line.retail_product_id} onChange={(event) => updateRetailDraft(index, { retail_product_id: event.target.value })}><option value="">Select product</option>{(context?.retail_products || []).map((product) => <option key={product.id} value={product.id}>{product.name}{product.sku ? ` · ${product.sku}` : ''}</option>)}</SearchableSelect></label>
                  <label className="form-field"><span>Qty</span><input type="number" min="1" step="1" value={line.quantity} onChange={(event) => updateRetailDraft(index, { quantity: event.target.value })} /></label>
                  <label className="form-field"><span>Staff</span><SearchableSelect value={line.assigned_personnel_id} onChange={(event) => updateRetailDraft(index, { assigned_personnel_id: event.target.value })}><option value="">Unassigned</option>{(context?.retail_personnel || []).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</SearchableSelect></label>
                  <label className="form-field"><span>Unit Price</span><input type="number" min="0" step="0.01" value={line.unit_price} onChange={(event) => updateRetailDraft(index, { unit_price: event.target.value })} /></label>
                </div><div className="transaction-action-retail-meta"><span>{(() => { const product=(context?.retail_products || []).find((row)=>String(row.id)===String(line.retail_product_id)); return product ? `Available ${product.available} ${product.unit || ''}` : '' })()}</span><button className="link-button danger-link" type="button" onClick={() => removeRetailDraft(index)}>Remove</button></div></div>) : <div className="empty-state">No retail products added.</div>}
              </section>
            </div> : null}
            <div className="table-wrap transaction-action-table-wrap">
              <table className="data-table transaction-action-table">
                <thead>
                  <tr>
                    {actionType === 'REFUND' ? <th className="transaction-action-check-column"></th> : null}
                    <th>Line</th>
                    <th>Current</th>
                    <th>{actionType === 'REFUND' ? 'Automatic Refund' : 'Correction Details'}</th>
                  </tr>
                </thead>
                <tbody>{(sale.items || []).map((line) => {
                  const edit = edits[line.id] || {}
                  const available = lineAvailableForAction(line, actionType)
                  const correctionChanged = actionType === 'CORRECTION' && correctionChangedLines.some((changedLine) => Number(changedLine.id) === Number(line.id))
                  return (
                    <tr key={line.id} className={(actionType === 'REFUND' ? selected[line.id] && available : correctionChanged) ? 'is-selected' : ''}>
                      {actionType === 'REFUND' ? (
                        <td className="transaction-action-check-column">
                          <input
                            type="checkbox"
                            aria-label={`Select ${lineName(line)}`}
                            disabled={!available}
                            checked={Boolean(selected[line.id]) && available}
                            onChange={(event) => setSelected((current) => ({ ...current, [line.id]: event.target.checked }))}
                          />
                        </td>
                      ) : null}
                      <td>
                        <div className="transaction-action-line-name">
                          <strong>{lineName(line)}</strong>
                          <span>{line.item_type}{terminalLine(line) ? ` · ${statusLabel(line.correction_status)}` : ''}{correctionChanged ? ' · Changed' : ''}</span>
                        </div>
                      </td>
                      <td>
                        <div className="transaction-action-current-values">
                          <span>Qty <strong>{line.quantity}</strong></span>
                          <span>{formatPeso(line.net_line_total || line.line_total || 0)}</span>
                          {line.assigned_personnel_name ? <span>{line.assigned_personnel_name}</span> : null}
                        </div>
                      </td>
                      <td>{actionType === 'REFUND' ? (
                        <div className="transaction-action-refund-summary">
                          {(() => {
                            const due = Number(line.refund_due_amount || 0)
                            const economic = terminalLine(line) ? 0 : Number(line.effective_net_line_total ?? line.net_line_total ?? line.line_total ?? 0)
                            const gross = Number(line.line_total || 0)
                            const discount = Number(line.discount_amount || 0)
                            const refundable = Math.max(0, due + economic)
                            return <>
                              <div className="refund-auto-row"><span>Gross</span><strong>{formatPeso(gross)}</strong></div>
                              {discount > 0.009 ? <div className="refund-auto-row"><span>Promotion / Discount</span><strong>-{formatPeso(discount)}</strong></div> : null}
                              {Array.isArray(line.promotion_applications) && line.promotion_applications.length > 1 ? (
                                <div className="transaction-action-promotion-breakdown">
                                  {line.promotion_applications.map((promo, index) => (
                                    <div className="promotion-refund-line" key={`${promo.rule_id || promo.id || index}-${index}`}>
                                      <span>{promo.promotion_code || promo.rule_name || `Promotion ${index + 1}`}</span>
                                      <strong>-{formatPeso(Number(promo.discount_amount || 0))}</strong>
                                    </div>
                                  ))}
                                </div>
                              ) : null}
                              <div className="refund-auto-row refund-auto-total"><span>Automatic Refund</span><strong>{formatPeso(refundable)}</strong></div>
                              <div className="transaction-action-inline-help">{terminalLine(line) ? 'Settles the existing Refund Due.' : 'Refund is calculated automatically for the whole transaction line using the final net amount after promotions.'}</div>
                            </>
                          })()}
                          {String(line.item_type || '').toUpperCase() === 'PRODUCT' && !terminalLine(line) ? (
                            <label className="transaction-action-stock-check">
                              <input type="checkbox" checked={Boolean(edit.return_to_stock)} onChange={(event) => updateEdit(line.id, { return_to_stock: event.target.checked })} />
                              <span>Returned to Stock</span>
                            </label>
                          ) : null}
                        </div>
                      ) : (
                        <div className="transaction-action-line-controls correction-controls">
                          <label className="form-field transaction-action-compact-field">
                            <span>Line Action</span>
                            <SearchableSelect value={edit.line_action} onChange={(event) => updateEdit(line.id, { line_action: event.target.value })}>
                              <option value="EDIT">Edit</option>
                              <option value="REMOVE">Remove</option>
                            </SearchableSelect>
                          </label>
                          {edit.line_action !== 'REMOVE' ? <>
                            <label className="form-field transaction-action-compact-field">
                              <span>Qty</span>
                              <input type="number" min="1" step="1" value={edit.quantity} onChange={(event) => updateEdit(line.id, { quantity: event.target.value })} />
                            </label>
                            <label className="form-field transaction-action-compact-field transaction-action-staff-field">
                              <span>Staff</span>
                              <SearchableSelect value={edit.assigned_personnel_id || ''} onChange={(event) => updateEdit(line.id, { assigned_personnel_id: event.target.value })}>
                                <option value="">Unassigned</option>
                                {(String(line.item_type || '').toUpperCase() === 'PRODUCT' ? (context?.retail_personnel || []) : (context?.personnel || [])).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                              </SearchableSelect>
                            </label>
                            <label className="form-field transaction-action-compact-field">
                              <span>Unit Price</span>
                              <input type="number" min="0" step="0.01" value={edit.unit_price} onChange={(event) => updateEdit(line.id, { unit_price: event.target.value })} />
                            </label>
                          </> : null}
                        </div>
                      )}</td>
                    </tr>
                  )
                })}</tbody>
              </table>
            </div>
          </section>
        ) : (
          <section className="transaction-action-warning-card">
            <strong>Whole transaction reversal</strong>
            <p>Reversal applies to the entire transaction. Individual line selection is intentionally disabled.</p>
          </section>
        )}

        {actionType === 'REFUND' ? (
          <section className="transaction-action-card">
            <div className="transaction-action-field-grid">
              <label className="form-field">
                <span>Refund Method</span>
                <SearchableSelect value={refundMethod} onChange={(event) => setRefundMethod(event.target.value)}>
                  {(context?.payment_methods?.length ? context.payment_methods : [
                    { id: 'cash', name: 'Cash' },
                    { id: 'gcash', name: 'GCash' },
                    { id: 'card', name: 'Card' },
                    { id: 'bank', name: 'Bank Transfer' },
                    { id: 'other', name: 'Other' },
                  ]).map((method) => <option key={method.id || method.name} value={method.name}>{method.name}</option>)}
                </SearchableSelect>
              </label>
              <label className="form-field">
                <span>Refund Reference</span>
                <input value={refundReference} onChange={(event) => setRefundReference(event.target.value)} placeholder="Optional reference" />
              </label>
            </div>
          </section>
        ) : null}

        <section className="transaction-action-card transaction-action-remarks-card">
          <label className="form-field">
            <span>Remarks *</span>
            <textarea rows="4" value={remarks} onChange={(event) => setRemarks(event.target.value)} placeholder="Describe what happened and what should be corrected." />
          </label>
          <SupportingDocumentField files={supportingFiles} onChange={setSupportingFiles} label="Supporting Document" hint="Optional. Attach customer acknowledgment, refund proof, correction evidence or other support for this request." />
        </section>

        <div className="transaction-action-guidance">
          {actionType === 'CORRECTION' ? (
            <p><strong>Correction flow:</strong> Before Checkout, approval recalculates the operational waterfall first, including completed service records, service consumption, retail inventory, staff assignment and other linked operational effects, then the corrected values become the Checkout basis. After Checkout, approval recalculates both the operational waterfall and financial waterfall. A decrease creates Refund Due; an increase creates Balance Due. Historical records remain traceable through counter-posting.</p>
          ) : null}
          <p>Submitting this form does not change the transaction. An authorized approver must review and post it first. Super Admin may manually approve their own request; approval is never automatic.</p>
        </div>

        <div className="modal-actions transaction-action-actions">
          <button className="secondary-button" type="button" onClick={onClose}>Cancel</button>
          <button className="primary-button" type="button" disabled={busy} onClick={submitRequest}>{busy ? 'Submitting...' : 'Submit for Manager Approval'}</button>
        </div>
      </div>
    </Modal>
  )
}
