import { formatDisplayDate } from '../../utils/formatters'

export default function ServiceCreditAvailability({ credits = [] }) {
  if (!credits.length) return null

  return (
    <section className="service-credit-availability form-span-2" aria-label="Available Service Credits">
      <div className="service-credit-availability-heading">
        <div>
          <strong>Service Credits</strong>
          <span>Available sessions detected for this customer</span>
        </div>
      </div>
      <div className="service-credit-availability-list">
        {credits.map((credit) => (
          <div className="service-credit-availability-row" key={credit.id}>
            <div className="service-credit-availability-main">
              <strong>{credit.service_name}</strong>
              <span>{credit.package_enrollment_code || credit.code}</span>
            </div>
            <div className="service-credit-availability-count">
              <strong>{credit.available_sessions}</strong>
              <span>{credit.available_sessions === 1 ? 'session available' : 'sessions available'}</span>
            </div>
            <div className="service-credit-availability-meta">
              <span>{credit.sessions_remaining} remaining{credit.reserved_sessions ? ` · ${credit.reserved_sessions} reserved` : ''}</span>
              {credit.expiry_date ? <span>Expires {formatDisplayDate(credit.expiry_date)}</span> : null}
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}
