import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import ActionMenu from '../../components/common/ActionMenu'
import Modal from '../../components/common/Modal'
import { navigationSections } from '../../components/navigation/navigationConfig'
import { useAuth } from '../../context/AuthContext'
import './users-roles.css'

const emptyUserForm = {
  id: null,
  username: '',
  display_name: '',
  email: '',
  role_id: '',
  default_branch_id: '',
  personnel_id: '',
  status: 'ACTIVE',
  access_code: '',
  force_reset: true,
}

const emptyRoleForm = {
  id: null,
  code: '',
  name: '',
  description: '',
  is_system: false,
}

function permissionLabel(value) {
  return String(value || '').replaceAll('-', ' ').replaceAll('_', ' ')
}

const permissionActionOrder = [
  'VIEW',
  'DOWNLOAD',
  'CREATE',
  'EDIT',
  'ADJUST',
  'COMPLETE',
  'APPROVE',
  'MARK_PAID',
  'TOGGLE',
  'RESET_CODE',
  'MANAGE_BRANCHES',
  'CHANGE_PRICE',
  'MANAGE_PERMISSIONS',
]

const permissionSectionExtras = {
  Operations: [
    { module: 'visits', label: 'Visits' },
    { module: 'fulfillment', label: 'Fulfillment' },
    { module: 'packages', label: 'Packages' },
  ],
  Sales: [
    { module: 'payments', label: 'Payments' },
  ],
  Accounting: [
    { module: 'cash-accounts', label: 'Cash Accounts' },
    { module: 'coa', label: 'Chart of Accounts' },
    { module: 'accounting-mapping', label: 'Accounting Mapping' },
  ],
  Administration: [
    { module: 'service-costing', label: 'Service Costing' },
    { module: 'package-setup', label: 'Package Setup' },
    { module: 'audit', label: 'Audit' },
  ],
}

function buildPermissionSectionDefinitions() {
  const seen = new Set()

  return navigationSections.map((section) => {
    const modules = []

    ;[...(section.items || []), ...(permissionSectionExtras[section.label] || [])].forEach((item) => {
      if (!item.module || seen.has(item.module)) return
      seen.add(item.module)
      modules.push({ module: item.module, label: item.label || permissionLabel(item.module) })
    })

    return { label: section.label, modules }
  })
}

const permissionSectionDefinitions = buildPermissionSectionDefinitions()

export default function UsersRolesPage() {
  const { hasPermission } = useAuth()
  const [activeTab, setActiveTab] = useState('users')
  const [users, setUsers] = useState([])
  const [roles, setRoles] = useState([])
  const [branches, setBranches] = useState([])
  const [personnel, setPersonnel] = useState([])
  const [permissionCatalog, setPermissionCatalog] = useState([])
  const [userForm, setUserForm] = useState(null)
  const [roleForm, setRoleForm] = useState(null)
  const [permissionRole, setPermissionRole] = useState(null)
  const [selectedPermissions, setSelectedPermissions] = useState([])
  const [permissionSearch, setPermissionSearch] = useState('')
  const [permissionSearchApplied, setPermissionSearchApplied] = useState('')
  const [collapsedPermissionSections, setCollapsedPermissionSections] = useState({})
  const [resetUser, setResetUser] = useState(null)
  const [resetCode, setResetCode] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const canManagePermissions = hasPermission('users', 'MANAGE_PERMISSIONS')

  const loadUsers = useCallback(async () => {
    const response = await api.get('/v1/admin/users')
    setUsers(response.data.users || [])
    setRoles(response.data.roles || [])
    setBranches(response.data.branches || [])
    setPersonnel(response.data.personnel || [])
  }, [])

  const loadRoles = useCallback(async () => {
    if (!canManagePermissions) return
    const response = await api.get('/v1/admin/roles')
    setRoles(response.data.roles || [])
    setPermissionCatalog(response.data.permission_catalog || [])
  }, [canManagePermissions])

  const reloadAll = useCallback(async () => {
    await Promise.all([loadUsers(), loadRoles()])
  }, [loadRoles, loadUsers])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      reloadAll()
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Users & Roles.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [reloadAll])

  const permissionSections = useMemo(() => {
    const grouped = new Map()

    permissionCatalog.forEach((permission) => {
      if (!grouped.has(permission.module)) grouped.set(permission.module, [])
      grouped.get(permission.module).push(permission)
    })

    const actionRank = new Map(permissionActionOrder.map((action, index) => [action, index]))
    grouped.forEach((permissions) => {
      permissions.sort((a, b) => {
        const aRank = actionRank.has(a.action) ? actionRank.get(a.action) : 999
        const bRank = actionRank.has(b.action) ? actionRank.get(b.action) : 999
        return aRank - bRank || String(a.action).localeCompare(String(b.action))
      })
    })

    const used = new Set()
    const sections = permissionSectionDefinitions
      .map((section) => ({
        label: section.label,
        modules: section.modules
          .filter((item) => grouped.has(item.module))
          .map((item) => {
            used.add(item.module)
            return { ...item, permissions: grouped.get(item.module) }
          }),
      }))
      .filter((section) => section.modules.length)

    const ungrouped = [...grouped.keys()]
      .filter((module) => !used.has(module))
      .sort((a, b) => a.localeCompare(b))
      .map((module) => ({ module, label: permissionLabel(module), permissions: grouped.get(module) }))

    if (ungrouped.length) sections.push({ label: 'Other', modules: ungrouped })

    return sections
  }, [permissionCatalog])

  const displayedPermissionSections = useMemo(() => {
    const term = permissionSearchApplied.trim().toLowerCase()
    if (!term) return permissionSections

    return permissionSections.map((section) => ({
      ...section,
      modules: section.modules.filter((module) => {
        const permissionText = module.permissions.map((permission) => `${permission.action} ${permission.name || ''}`).join(' ')
        return `${section.label} ${module.label} ${module.module} ${permissionText}`.toLowerCase().includes(term)
      }),
    })).filter((section) => section.modules.length)
  }, [permissionSearchApplied, permissionSections])

  function togglePermissionSection(label) {
    setCollapsedPermissionSections((current) => ({ ...current, [label]: !current[label] }))
  }

  function applyPermissionSearch(event) {
    event?.preventDefault()
    setPermissionSearchApplied(permissionSearch.trim())
    if (permissionSearch.trim()) setCollapsedPermissionSections({})
  }

  function clearPermissionSearch() {
    setPermissionSearch('')
    setPermissionSearchApplied('')
  }

  function openCreateUser() {
    setMessage('')
    setUserForm({ ...emptyUserForm, role_id: roles.find((r) => r.active)?.id || '' })
  }

  function openEditUser(user) {
    setMessage('')
    setUserForm({
      id: user.id,
      username: user.username || '',
      display_name: user.display_name || '',
      email: user.email || '',
      role_id: user.role?.id || '',
      default_branch_id: user.default_branch?.id || '',
      personnel_id: user.personnel?.id || '',
      status: user.status || 'ACTIVE',
      access_code: '',
      force_reset: Boolean(user.force_reset),
    })
  }

  async function saveUser(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const payload = {
        username: userForm.username,
        display_name: userForm.display_name,
        email: userForm.email || null,
        role_id: Number(userForm.role_id),
        default_branch_id: userForm.default_branch_id ? Number(userForm.default_branch_id) : null,
        personnel_id: userForm.personnel_id ? Number(userForm.personnel_id) : null,
        status: userForm.status,
        access_code: userForm.access_code || null,
        force_reset: Boolean(userForm.force_reset),
      }
      if (userForm.id) {
        await api.put(`/v1/admin/users/${userForm.id}`, payload)
      } else {
        await api.post('/v1/admin/users', payload)
      }
      setUserForm(null)
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save user account.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggleUser(user) {
    setMessage('')
    try {
      await api.patch(`/v1/admin/users/${user.id}/toggle`)
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to change user status.'))
    }
  }

  async function submitReset(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      await api.post(`/v1/admin/users/${resetUser.id}/reset-access-code`, {
        access_code: resetCode,
        force_reset: true,
      })
      setResetUser(null)
      setResetCode('')
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to reset Access Code.'))
    } finally {
      setSaving(false)
    }
  }

  function openCreateRole() {
    setMessage('')
    setRoleForm({ ...emptyRoleForm })
  }

  function openEditRole(role) {
    setMessage('')
    setRoleForm({
      id: role.id,
      code: role.code,
      name: role.name,
      description: role.description || '',
      is_system: Boolean(role.is_system),
    })
  }

  async function saveRole(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    try {
      const payload = {
        code: roleForm.id ? null : roleForm.code,
        name: roleForm.name,
        description: roleForm.description || null,
      }
      if (roleForm.id) {
        await api.put(`/v1/admin/roles/${roleForm.id}`, payload)
      } else {
        await api.post('/v1/admin/roles', payload)
      }
      setRoleForm(null)
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save role.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggleRole(role) {
    setMessage('')
    try {
      await api.patch(`/v1/admin/roles/${role.id}/toggle`)
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to change role status.'))
    }
  }

  function openPermissions(role) {
    setPermissionRole(role)
    setSelectedPermissions(role.permissions || [])
    setPermissionSearch('')
    setPermissionSearchApplied('')
    setCollapsedPermissionSections({})
  }

  function togglePermission(code) {
    setSelectedPermissions((current) => (
      current.includes(code) ? current.filter((item) => item !== code) : [...current, code]
    ))
  }

  async function savePermissions() {
    setSaving(true)
    setMessage('')
    try {
      await api.put(`/v1/admin/roles/${permissionRole.id}/permissions`, {
        permissions: selectedPermissions,
      })
      setPermissionRole(null)
      await reloadAll()
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update role permissions.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading users-role-heading">
        <div>
          <h1>Users & Roles</h1>
          <p>Manage system accounts, linked personnel, roles, and module access.</p>
        </div>
        <div className="users-role-actions">
          {activeTab === 'users' && hasPermission('users', 'CREATE') ? (
            <button className="primary-button" type="button" onClick={openCreateUser}>+ Add User</button>
          ) : null}
          {activeTab === 'roles' && canManagePermissions ? (
            <button className="primary-button" type="button" onClick={openCreateRole}>+ Add Role</button>
          ) : null}
        </div>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <div className="booking-tabs users-role-tabs">
        <button className={activeTab === 'users' ? 'active' : ''} type="button" onClick={() => setActiveTab('users')}>Users</button>
        {canManagePermissions ? (
          <button className={activeTab === 'roles' ? 'active' : ''} type="button" onClick={() => setActiveTab('roles')}>Roles & Access</button>
        ) : null}
      </div>

      {activeTab === 'users' ? (
        <section className="content-card">
          {loading ? <div className="empty-state">Loading users...</div> : (
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>User</th><th>Role</th><th>Linked Personnel</th><th>Default Branch</th><th>Status</th><th>Last Login</th><th className="actions-column">Action</th></tr></thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id}>
                      <td><strong>{user.display_name}</strong><div className="table-subline">@{user.username}{user.email ? ` · ${user.email}` : ''}</div></td>
                      <td>{user.role?.name || '—'}<div className="table-subline">{user.role?.code || ''}</div></td>
                      <td>{user.personnel ? <><strong>{user.personnel.full_name}</strong><div className="table-subline">{user.personnel.code}</div></> : '—'}</td>
                      <td>{user.default_branch?.name || '—'}</td>
                      <td><span className={`status-pill ${user.status === 'ACTIVE' ? 'success' : 'neutral'}`}>{user.status}</span>{user.force_reset ? <div className="table-subline">Reset required</div> : null}</td>
                      <td>{user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never'}</td>
                      <td className="action-cell"><ActionMenu items={[
                        hasPermission('users', 'EDIT') ? { label: 'Edit', onClick: () => openEditUser(user) } : null,
                        hasPermission('users', 'RESET_CODE') ? { label: 'Reset Access Code', onClick: () => { setResetUser(user); setResetCode('') } } : null,
                        hasPermission('users', 'EDIT') ? { label: user.status === 'ACTIVE' ? 'Deactivate' : 'Activate', onClick: () => toggleUser(user) } : null,
                      ]} /></td>
                    </tr>
                  ))}
                  {!users.length ? <tr><td colSpan="7"><div className="empty-state">No user accounts found.</div></td></tr> : null}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : (
        <section className="content-card">
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Role</th><th>Description</th><th>Active Users</th><th>Status</th><th>Type</th><th className="actions-column">Action</th></tr></thead>
              <tbody>
                {roles.map((role) => (
                  <tr key={role.id}>
                    <td><strong>{role.name}</strong><div className="table-subline">{role.code}</div></td>
                    <td>{role.description || '—'}</td>
                    <td>{role.active_users_count ?? '—'}</td>
                    <td><span className={`status-pill ${role.active ? 'success' : 'neutral'}`}>{role.active ? 'Active' : 'Inactive'}</span></td>
                    <td>{role.is_super_admin ? 'Super Admin' : role.is_system ? 'System' : 'Custom'}</td>
                    <td className="action-cell"><ActionMenu items={[
                      { label: 'Edit Role', onClick: () => openEditRole(role) },
                      !role.is_super_admin ? { label: 'Manage Permissions', onClick: () => openPermissions(role) } : null,
                      !role.is_super_admin ? { label: role.active ? 'Deactivate' : 'Activate', onClick: () => toggleRole(role) } : null,
                    ]} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {userForm ? (
        <Modal title={userForm.id ? 'Edit User' : 'Add User'} subtitle="One role per user. Link a Personnel record for My Attendance and other employee self-service modules." onClose={() => setUserForm(null)} wide>
          <form className="form-grid" onSubmit={saveUser}>
            <label className="form-field">Display Name<input value={userForm.display_name} onChange={(e) => setUserForm((c) => ({ ...c, display_name: e.target.value }))} required /></label>
            <label className="form-field">Username<input value={userForm.username} onChange={(e) => setUserForm((c) => ({ ...c, username: e.target.value }))} required /></label>
            <label className="form-field">Email<input type="email" value={userForm.email} onChange={(e) => setUserForm((c) => ({ ...c, email: e.target.value }))} /></label>
            <label className="form-field">Role<SearchableSelect value={userForm.role_id} onChange={(e) => setUserForm((c) => ({ ...c, role_id: e.target.value }))} required><option value="">Select role</option>{roles.filter((role) => role.active || role.id === Number(userForm.role_id)).map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</SearchableSelect></label>
            <label className="form-field">Linked Personnel<SearchableSelect value={userForm.personnel_id} onChange={(e) => setUserForm((c) => ({ ...c, personnel_id: e.target.value }))}><option value="">Not linked</option>{personnel.filter((p) => !p.linked_user || p.linked_user.id === userForm.id).map((p) => <option key={p.id} value={p.id}>{p.full_name} ({p.code})</option>)}</SearchableSelect></label>
            <label className="form-field">Default Branch<SearchableSelect value={userForm.default_branch_id} onChange={(e) => setUserForm((c) => ({ ...c, default_branch_id: e.target.value }))}><option value="">No default branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
            <label className="form-field">Status<SearchableSelect value={userForm.status} onChange={(e) => setUserForm((c) => ({ ...c, status: e.target.value }))}><option value="ACTIVE">Active</option><option value="INACTIVE">Inactive</option></SearchableSelect></label>
            <label className="form-field">{userForm.id ? 'New Access Code (optional)' : 'Temporary Access Code'}<input type="password" value={userForm.access_code} onChange={(e) => setUserForm((c) => ({ ...c, access_code: e.target.value }))} required={!userForm.id} minLength="6" /></label>
            <label className="checkbox-line form-span-2"><input type="checkbox" checked={userForm.force_reset} onChange={(e) => setUserForm((c) => ({ ...c, force_reset: e.target.checked }))} /> Require user to change Access Code on next login</label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setUserForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save User'}</button></div>
          </form>
        </Modal>
      ) : null}

      {resetUser ? (
        <Modal title="Reset Access Code" subtitle={`${resetUser.display_name} will be required to change the temporary code after login.`} onClose={() => setResetUser(null)}>
          <form className="form-grid" onSubmit={submitReset}>
            <label className="form-field form-span-2">Temporary Access Code<input type="password" value={resetCode} onChange={(e) => setResetCode(e.target.value)} minLength="6" required autoFocus /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setResetUser(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Resetting...' : 'Reset Access Code'}</button></div>
          </form>
        </Modal>
      ) : null}

      {roleForm ? (
        <Modal title={roleForm.id ? 'Edit Role' : 'Add Role'} subtitle="Roles control which modules and actions a user can access." onClose={() => setRoleForm(null)}>
          <form className="form-grid" onSubmit={saveRole}>
            <label className="form-field">Role Code<input value={roleForm.code} disabled={Boolean(roleForm.id)} onChange={(e) => setRoleForm((c) => ({ ...c, code: e.target.value.toUpperCase() }))} placeholder="ROLE-CUSTOM" required={!roleForm.id} /></label>
            <label className="form-field">Role Name<input value={roleForm.name} onChange={(e) => setRoleForm((c) => ({ ...c, name: e.target.value }))} required /></label>
            <label className="form-field form-span-2">Description<textarea value={roleForm.description} onChange={(e) => setRoleForm((c) => ({ ...c, description: e.target.value }))} rows="3" /></label>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={() => setRoleForm(null)}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Role'}</button></div>
          </form>
        </Modal>
      ) : null}

      {permissionRole ? (
        <Modal title={`Permissions · ${permissionRole.name}`} subtitle="Permissions follow the same functional grouping as the side navigation. Selecting an action automatically preserves VIEW access for that module on the backend." onClose={() => setPermissionRole(null)} wide>
          <form className="permission-search-row" onSubmit={applyPermissionSearch}>
            <input type="search" value={permissionSearch} onChange={(event) => setPermissionSearch(event.target.value)} placeholder="Search module or permission" />
            <button className="primary-button compact-button" type="submit">Search</button>
            <button className="secondary-button compact-button" type="button" onClick={clearPermissionSearch}>Clear</button>
          </form>
          <div className="permission-list">
            {displayedPermissionSections.map((section) => {
              const collapsed = Boolean(collapsedPermissionSections[section.label])
              return (
                <section className="permission-section" key={section.label}>
                  <button className="permission-section-heading permission-section-toggle" type="button" onClick={() => togglePermissionSection(section.label)} aria-expanded={!collapsed}>
                    <strong>{section.label}</strong>
                    <span>{section.modules.length} {section.modules.length === 1 ? 'module' : 'modules'} · {collapsed ? 'Show' : 'Hide'}</span>
                  </button>
                  {!collapsed ? <div className="permission-section-rows">
                    {section.modules.map((module) => (
                      <div className="permission-row" key={module.module}>
                        <div className="permission-row-module">
                          <strong>{module.label}</strong>
                        </div>
                        <div className="permission-row-actions">
                          {module.permissions.map((permission) => (
                            <label key={permission.code} className="checkbox-line permission-checkbox">
                              <input type="checkbox" checked={selectedPermissions.includes(permission.code)} onChange={() => togglePermission(permission.code)} />
                              <span>{permissionLabel(permission.action)}</span>
                            </label>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div> : null}
                </section>
              )
            })}
            {!displayedPermissionSections.length ? <div className="empty-state compact-empty">No permission modules match your search.</div> : null}
          </div>
          <div className="modal-actions permission-modal-actions"><button className="secondary-button" type="button" onClick={() => setPermissionRole(null)}>Cancel</button><button className="primary-button" type="button" onClick={savePermissions} disabled={saving}>{saving ? 'Saving...' : 'Save Permissions'}</button></div>
        </Modal>
      ) : null}
    </div>
  )
}
