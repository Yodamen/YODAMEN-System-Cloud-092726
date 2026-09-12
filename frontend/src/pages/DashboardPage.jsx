import { useAuth } from '../context/AuthContext'

export default function DashboardPage() {
  const { user, bootstrap } = useAuth()

  return (
    <div className="page-stack">
      <section className="page-heading">
        <h1>Dashboard</h1>
        <span className="status-pill success">Phase 2C</span>
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
            <p>Master records are established before operational transactions are migrated.</p>
          </div>
        </div>
        <div className="foundation-checks">
          <div><span>✓</span> Sanctum session authentication</div>
          <div><span>✓</span> Role and action permissions</div>
          <div><span>✓</span> Reference Lists</div>
          <div><span>✓</span> Effective-dated Services &amp; Pricing</div>
          <div><span>✓</span> Personnel and compensation profile</div>
          <div><span>✓</span> SQL audit trail</div>
        </div>
      </section>
    </div>
  )
}
