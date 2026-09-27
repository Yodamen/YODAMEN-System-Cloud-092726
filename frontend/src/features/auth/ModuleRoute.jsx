import { Navigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

export default function ModuleRoute({ module, action = 'VIEW', requiresOperationalCompensation = false, children }) {
  const { hasPermission, user } = useAuth()

  if (!hasPermission(module, action)) {
    return <Navigate to="/" replace />
  }

  if (requiresOperationalCompensation && !user?.personnel?.has_operational_compensation) {
    return <Navigate to="/" replace />
  }

  return children
}
