import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
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
  const { hasPermission, user, logout, bootstrap } = useAuth()
  const location = useLocation()
  const [loggingOut, setLoggingOut] = useState(false)

  const visibleSections = useMemo(() => navigationSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        if (!hasPermission(item.module, 'VIEW')) return false
        if (item.requiresSuperAdmin && !user?.role?.is_super_admin) return false
        if (item.requiresOperationalCompensation && !user?.personnel?.has_operational_compensation) return false
        return true
      }),
    }))
    .filter((section) => section.items.length), [hasPermission, user?.personnel?.has_operational_compensation, user?.role?.is_super_admin])

  const activeItemPath = useMemo(() => {
    const matches = visibleSections
      .flatMap((section) => section.items)
      .filter((item) => itemMatchesPath(item, location.pathname))
      .sort((a, b) => String(b.path || '').length - String(a.path || '').length)

    return matches[0]?.path || null
  }, [location.pathname, visibleSections])

  const activeSection = useMemo(() => {
    const match = visibleSections.find((section) => section.items.some((item) => item.path === activeItemPath))
    return match?.label || 'Main'
  }, [activeItemPath, visibleSections])

  const [openSection, setOpenSection] = useState(() => (activeSection === 'Main' ? null : activeSection))

  useEffect(() => {
    if (activeSection === 'Main') return undefined

    const timeoutId = window.setTimeout(() => {
      setOpenSection(activeSection)
    }, 0)

    return () => window.clearTimeout(timeoutId)
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
        <Link
          className={`nav-item ${item.path === activeItemPath ? 'active' : ''}`}
          key={`${sectionLabel}-${item.label}`}
          to={item.path}
          aria-current={item.path === activeItemPath ? 'page' : undefined}
          title={collapsed ? item.label : undefined}
        >
          {content}
        </Link>
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
      <div className={`sidebar-brand ${bootstrap?.branding?.logo_url ? 'has-logo' : ''}`}>
        {bootstrap?.branding?.logo_url ? (
          <img
            className={`sidebar-brand-logo ${collapsed ? 'compact' : 'wide'}`}
            src={bootstrap.branding.logo_url}
            alt={bootstrap.branding.business_display_name || 'Business logo'}
          />
        ) : (
          <>
            <div className="brand-mark small">{String(bootstrap?.branding?.business_display_name || 'Y').charAt(0).toUpperCase()}</div>
            {!collapsed && (
              <div>
                <strong>{bootstrap?.branding?.business_display_name || 'YODAMEN'}</strong>
                <span>{bootstrap?.branding?.system_name || 'Management System'}</span>
              </div>
            )}
          </>
        )}
      </div>

      <nav className="sidebar-nav" aria-label="Primary navigation">
        {visibleSections.map((section) => {
          const isMain = section.label === 'Main'
          const isOpen = isMain || openSection === section.label

          return (
            <div className={`nav-section ${isOpen ? 'open' : 'closed'}`} key={section.label}>
              {!collapsed && !isMain ? (
                <button
                  className="nav-section-toggle"
                  type="button"
                  onClick={() => setOpenSection((current) => (current === section.label ? null : section.label))}
                  aria-expanded={isOpen}
                >
                  <span>{section.label}</span>
                  <span className="nav-section-chevron" aria-hidden="true">{isOpen ? '⌃' : '⌄'}</span>
                </button>
              ) : null}

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
            <strong>{bootstrap?.branding?.business_display_name || 'YODAMEN'}</strong>
            <span>{bootstrap?.branding?.subtitle || bootstrap?.business?.name || 'Grooming Lounge'}</span>
          </div>
          <button className="sidebar-logout-button" type="button" onClick={handleLogout} disabled={loggingOut}>
            {loggingOut ? 'Logging out...' : 'Logout'}
          </button>
        </div>
      ) : null}
    </aside>
  )
}
