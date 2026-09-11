import { useAuth } from '../context/AuthContext'

export default function DashboardPage() {
  const { user, bootstrap } = useAuth()

  return (
    <div className="page-stack">
      <section className="page-heading">
        <div>
          <span className="eyebrow gold">Foundation</span>
          <h1>Dashboard</h1>
          <p>YODAMEN React + Laravel foundation is active. Business modules remain intentionally disabled until their migration phases.</p>
        </div>
        <span className="status-pill success">Phase 1A</span>
      </section>

      <section className="metric-grid">
        <article className="metric-card">
          <span>Signed in as</span>
          <strong>{user?.display_name}</strong>
          <small>{user?.role?.name}</small>
        </article>
        <article className="metric-card">
          <span>Business</span>
          <strong>{bootstrap?.business?.name || 'YODAMEN'}</strong>
          <small>{bootstrap?.business?.currency || 'PHP'} · {bootstrap?.business?.timezone || 'Asia/Manila'}</small>
        </article>
        <article className="metric-card">
          <span>Default branch</span>
          <strong>{user?.default_branch?.name || 'Not assigned'}</strong>
          <small>{user?.default_branch?.code || '—'}</small>
        </article>
        <article className="metric-card">
          <span>Permissions</span>
          <strong>{user?.permissions?.length || 0}</strong>
          <small>Server-authoritative grants</small>
        </article>
      </section>

      <section className="content-card">
        <div className="card-header">
          <div>
            <h2>Migration status</h2>
            <p>Foundation controls are installed before operational replication begins.</p>
          </div>
        </div>
        <div className="foundation-checks">
          <div><span>✓</span> Sanctum session authentication</div>
          <div><span>✓</span> Role and action permissions</div>
          <div><span>✓</span> SQL audit trail</div>
          <div><span>✓</span> Asia/Manila business clock</div>
          <div><span>✓</span> Code sequence and idempotency infrastructure</div>
        </div>
      </section>
    </div>
  )
}
