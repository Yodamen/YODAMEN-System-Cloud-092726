import { NavLink } from 'react-router-dom'
import { navigationSections } from './navigationConfig'
import { useAuth } from '../../context/AuthContext'

export default function Sidebar({ collapsed, onToggle }) {
  const { hasPermission } = useAuth()

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
        {navigationSections.map((section) => {
          const visibleItems = section.items.filter((item) => hasPermission(item.module, 'VIEW'))
          if (!visibleItems.length) return null

          return (
            <div className="nav-section" key={section.label}>
              {!collapsed && <div className="nav-section-label">{section.label}</div>}
              {visibleItems.map((item) => {
                if (item.enabled) {
                  return (
                    <NavLink
                      className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                      key={`${section.label}-${item.label}`}
                      to={item.path}
                      end
                    >
                      <span className="nav-dot" />
                      {!collapsed && <span>{item.label}</span>}
                    </NavLink>
                  )
                }

                return (
                  <button
                    type="button"
                    className="nav-item disabled"
                    key={`${section.label}-${item.label}`}
                    title={`${item.label} will be activated in a later migration phase.`}
                    disabled
                  >
                    <span className="nav-dot" />
                    {!collapsed && <span>{item.label}</span>}
                  </button>
                )
              })}
            </div>
          )
        })}
      </nav>

      <button className="sidebar-toggle" type="button" onClick={onToggle}>
        {collapsed ? '›' : '‹ Collapse'}
      </button>
    </aside>
  )
}
