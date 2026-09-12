import { useEffect, useMemo, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { navigationSections } from './navigationConfig'
import { useAuth } from '../../context/AuthContext'

function itemMatchesPath(item, pathname) {
  if (!item.path) return false
  if (item.path === '/') return pathname === '/'
  return pathname === item.path || pathname.startsWith(`${item.path}/`)
}

function itemAbbreviation(label) {
  const words = String(label || '').trim().split(/\s+/).filter(Boolean)
  if (!words.length) return '•'
  return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('')
}

export default function Sidebar({ collapsed, onToggle }) {
  const { hasPermission, user, logout } = useAuth()
  const location = useLocation()
  const [loggingOut, setLoggingOut] = useState(false)

  const visibleSections = useMemo(() => navigationSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => hasPermission(item.module, 'VIEW')),
    }))
    .filter((section) => section.items.length), [hasPermission])

  const activeSection = useMemo(() => {
    const match = visibleSections.find((section) => section.items.some((item) => itemMatchesPath(item, location.pathname)))
    return match?.label || 'Main'
  }, [location.pathname, visibleSections])

  const [openSection, setOpenSection] = useState(() => (activeSection === 'Main' ? null : activeSection))

  useEffect(() => {
    if (activeSection !== 'Main') {
      setOpenSection(activeSection)
    }
  }, [activeSection])

  async function handleLogout() {
    setLoggingOut(true)
    try {
      await logout()
    } finally {
      setLoggingOut(false)
    }
  }

  function renderItem(item, sectionLabel) {
    const content = (
      <>
        {collapsed ? <span className="nav-item-abbr" aria-hidden="true">{itemAbbreviation(item.label)}</span> : null}
        {!collapsed && <span className="nav-item-label">{item.label}</span>}
      </>
    )

    if (item.enabled) {
      return (
        <NavLink
          className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
          key={`${sectionLabel}-${item.label}`}
          to={item.path}
          end={item.path === '/'}
          title={collapsed ? item.label : undefined}
        >
          {content}
        </NavLink>
      )
    }

    return (
      <button
        type="button"
        className="nav-item disabled"
        key={`${sectionLabel}-${item.label}`}
        title={`${item.label} will be activated in a later migration phase.`}
        disabled
      >
        {content}
      </button>
    )
  }

  return (
    <aside className={`sidebar ${collapsed ? 'collapsed' : ''}`}>
      <div className="sidebar-brand">
        <div className="brand-mark small">Y</div>
        {!collapsed && (
          <div>
            <strong>YODAMEN</strong>
            <span>Management System</span>
          </div>
        )}
      </div>

      <nav className="sidebar-nav" aria-label="Primary navigation">
        {visibleSections.map((section) => {
          const isMain = section.label === 'Main'
          const isOpen = isMain || openSection === section.label

          return (
            <div className={`nav-section ${isOpen ? 'open' : 'closed'}`} key={section.label}>
              {!collapsed && (
                isMain ? (
                  <div className="nav-section-label nav-section-label-static">{section.label}</div>
                ) : (
                  <button
                    className="nav-section-toggle"
                    type="button"
                    onClick={() => setOpenSection((current) => (current === section.label ? null : section.label))}
                    aria-expanded={isOpen}
                  >
                    <span>{section.label}</span>
                    <span className="nav-section-chevron" aria-hidden="true">{isOpen ? '⌃' : '⌄'}</span>
                  </button>
                )
              )}

              {isOpen ? (
                <div className="nav-section-items">
                  {section.items.map((item) => renderItem(item, section.label))}
                </div>
              ) : null}
            </div>
          )
        })}
      </nav>

      <button className="sidebar-toggle" type="button" onClick={onToggle}>
        {collapsed ? '›' : '‹ Collapse'}
      </button>

      {!collapsed ? (
        <div className="sidebar-footer">
          <div className="sidebar-footer-user">
            <strong>{user?.display_name}</strong>
            <span>{user?.role?.name}</span>
          </div>
          <div className="sidebar-footer-system">
            <strong>YODAMEN</strong>
            <span>Grooming Lounge</span>
          </div>
          <button className="sidebar-logout-button" type="button" onClick={handleLogout} disabled={loggingOut}>
            {loggingOut ? 'Logging out...' : 'Logout'}
          </button>
        </div>
      ) : null}
    </aside>
  )
}
