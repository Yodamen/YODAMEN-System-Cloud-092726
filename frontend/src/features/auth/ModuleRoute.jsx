import { Link, Navigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

export default function ModuleRoute({ module, action = 'VIEW', requiresOperationalCompensation = false, children }) {
  const { hasPermission, hasModuleAccess, subscription, user } = useAuth()

  if (!hasModuleAccess(module)) {
    return <section className="content-card"><h1>Plan upgrade required</h1><p>This feature is not included in your {subscription?.name || 'current'} plan. Contact your provider for an activation code.</p><Link to="/">Back to dashboard</Link></section>
  }

  if (!hasPermission(module, action)) {
    return <Navigate to="/" replace />
  }

  if (requiresOperationalCompensation && !user?.personnel?.has_operational_compensation) {
    return <Navigate to="/" replace />
  }

  return children
}
