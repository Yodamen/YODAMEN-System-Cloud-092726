export default function ConfirmationModal({
  customerName,
  bookingCode,
  services = [],
  cancelLabel = 'Cancel',
  confirmLabel,
  onCancel,
  onConfirm,
  busy = false,
}) {
  const serviceNames = services
    .map((service) => service?.service_name || service?.name || service?.label || '')
    .filter(Boolean)

  return (
    <div className="confirm-backdrop" role="presentation" onMouseDown={onCancel}>
      <section
        className="confirm-card"
        role="dialog"
        aria-modal="true"
        aria-label={confirmLabel}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="confirm-header">
          <h2>{customerName || 'Customer'}</h2>
          <p>Booking ID: {bookingCode || '—'}</p>
        </header>

        <div className="confirm-body">
          <div className="confirm-section-label">Services</div>
          <div className="confirm-service-list">
            {serviceNames.length ? serviceNames.map((serviceName, index) => (
              <div className="confirm-service-item" key={`${serviceName}-${index}`}>
                <span className="confirm-service-check" aria-hidden="true">✓</span>
                <strong>{serviceName}</strong>
              </div>
            )) : (
              <div className="confirm-service-item muted">
                <span className="confirm-service-check" aria-hidden="true">✓</span>
                <strong>No service listed</strong>
              </div>
            )}
          </div>
        </div>

        <footer className="confirm-footer">
          <button className="secondary-button" type="button" disabled={busy} onClick={onCancel}>{cancelLabel}</button>
          <button className="primary-button" type="button" disabled={busy} onClick={onConfirm}>{busy ? 'Processing...' : confirmLabel}</button>
        </footer>
      </section>
    </div>
  )
}
