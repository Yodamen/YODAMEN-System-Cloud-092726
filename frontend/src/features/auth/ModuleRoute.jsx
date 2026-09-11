import { Navigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

export default function ModuleRoute({ module, action = 'VIEW', children }) {
  const { hasPermission } = useAuth()

  if (!hasPermission(module, action)) {
    return <Navigate to="/" replace />
  }

  return children
}
