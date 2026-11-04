import { useAuth } from '../context/AuthContext'

export default function DashboardPage() {
  const { user, bootstrap } = useAuth()

  return (
    <div className="page-stack">
      <section className="page-heading">
        <h1>Dashboard</h1>
        <span className="status-pill success">{bootstrap?.subscription?.name || 'Basic'} plan</span>
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
            <h2>Your subscription</h2>
            <p>Feature access follows your plan and the permissions assigned to your account.</p>
            {['trial', 'trial_exhausted'].includes(bootstrap?.subscription?.status) && <p>Premium trial: {bootstrap.subscription.trial_used} of {bootstrap.subscription.trial_limit} activities used.</p>}
            {bootstrap?.subscription?.is_read_only && <p role="status">Your trial or license has ended. Existing records remain available to view. Contact your provider for activation.</p>}
            {bootstrap?.subscription?.expires_at && <p>License expires: {new Date(bootstrap.subscription.expires_at).toLocaleString()}</p>}
          </div>
        </div>
        <div className="foundation-checks">
          {bootstrap?.subscription?.feature_catalog?.map((feature) => <div key={feature.code}><span>{feature.included ? '✓' : '—'}</span> {feature.name}: {feature.included ? 'Included' : 'Upgrade required'}</div>)}
        </div>
      </section>
    </div>
  )
}
