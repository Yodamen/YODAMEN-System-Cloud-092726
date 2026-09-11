import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from '../components/navigation/Sidebar'
import AccessCodeResetModal from '../features/auth/AccessCodeResetModal'
import { useAuth } from '../context/AuthContext'

export default function AppLayout() {
  const { user, bootstrap, logout } = useAuth()
  const [collapsed, setCollapsed] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)

  async function handleLogout() {
    setLoggingOut(true)
    try {
      await logout()
    } finally {
      setLoggingOut(false)
    }
  }

  return (
    <div className="app-shell">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((value) => !value)} />

      <div className="app-main">
        <header className="topbar">
          <div>
            <div className="topbar-business">{bootstrap?.business?.name || 'YODAMEN Grooming Lounge'}</div>
            <div className="topbar-meta">
              {bootstrap?.system?.business_date ? `Business Date: ${bootstrap.system.business_date}` : 'Foundation environment'}
            </div>
          </div>

          <div className="user-menu">
            <div className="user-meta">
              <strong>{user?.display_name}</strong>
              <span>{user?.role?.name}</span>
            </div>
            <button className="secondary-button" type="button" onClick={handleLogout} disabled={loggingOut}>
              {loggingOut ? 'Signing out...' : 'Sign Out'}
            </button>
          </div>
        </header>

        <main className="content-area">
          <Outlet />
        </main>
      </div>

      <AccessCodeResetModal />
    </div>
  )
}
