export default function ServiceList({ services = [], showStaff = false, excludeCancelled = false }) {
  const visible = services.filter((service) => !excludeCancelled || String(service.status || '').toUpperCase() !== 'CANCELLED')

  if (!visible.length) {
    return <span className="service-list-empty">—</span>
  }

  return (
    <div className="service-list-stack">
      {visible.map((service, index) => (
        <div className="service-list-line" key={service.id || service.code || `${service.service_name || service.service_name_snapshot || 'service'}-${index}`}>
          <span>{service.service_name || service.service_name_snapshot || service.name || 'Service'}{index < visible.length - 1 ? ',' : ''}</span>
          {showStaff ? <small>{service.assigned_personnel_name || 'Staff unassigned'}</small> : null}
        </div>
      ))}
    </div>
  )
}
