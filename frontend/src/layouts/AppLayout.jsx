import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from '../components/navigation/Sidebar'
import AccessCodeResetModal from '../features/auth/AccessCodeResetModal'
import { useAuth } from '../context/AuthContext'

function userInitial(name) {
  const value = String(name || '').trim()
  return value ? value.charAt(0).toUpperCase() : 'Y'
}

export default function AppLayout() {
  const { user } = useAuth()
  const [collapsed, setCollapsed] = useState(false)

  return (
    <div className="app-shell">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((value) => !value)} />

      <div className="app-main">
        <header className="topbar">
          <div className="topbar-spacer" />
          <div className="topbar-user-identity">
            <span className="user-avatar" aria-hidden="true">{userInitial(user?.display_name)}</span>
            <div className="user-meta">
              <strong>{user?.display_name}</strong>
              <span>{user?.role?.name}</span>
            </div>
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
