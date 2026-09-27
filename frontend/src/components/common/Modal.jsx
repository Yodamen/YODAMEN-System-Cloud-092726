export default function Modal({ title, subtitle, onClose, children, wide = false, className = '', headerAction = null }) {
  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section
        className={`modal-card ${wide ? 'wide' : ''} ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="modal-header">
          <div>
            <h2>{title}</h2>
            {subtitle ? <p>{subtitle}</p> : null}
          </div>
          <div className="modal-header-actions">
            {headerAction}
            <button className="icon-button" type="button" onClick={onClose} aria-label="Close">
              ×
            </button>
          </div>
        </header>
        <div className="modal-body">{children}</div>
      </section>
    </div>
  )
}
