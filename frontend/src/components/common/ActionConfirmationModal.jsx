import Modal from './Modal'

export default function ActionConfirmationModal({
  title = 'Confirm Action',
  subtitle = '',
  message = '',
  details = [],
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  danger = false,
  busy = false,
  onCancel,
  onConfirm,
}) {
  return (
    <Modal title={title} subtitle={subtitle} onClose={onCancel} className="action-confirm-modal">
      <div className="action-confirm-content">
        {message ? <p className="action-confirm-message">{message}</p> : null}
        {details.length ? (
          <div className="action-confirm-details">
            {details.map((detail) => (
              <div key={`${detail.label}-${detail.value}`}>
                <span>{detail.label}</span>
                <strong>{detail.value || '—'}</strong>
              </div>
            ))}
          </div>
        ) : null}
        <div className="modal-actions action-confirm-actions">
          <button className="secondary-button" type="button" disabled={busy} onClick={onCancel}>{cancelLabel}</button>
          <button className={danger ? 'danger-button' : 'primary-button'} type="button" disabled={busy} onClick={onConfirm}>
            {busy ? 'Processing...' : confirmLabel}
          </button>
        </div>
      </div>
    </Modal>
  )
}
