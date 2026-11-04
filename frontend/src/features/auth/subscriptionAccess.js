export function hasModuleAccess(subscription, module) {
  return Array.isArray(subscription?.modules) && subscription.modules.includes(module)
}

export function canPerform(user, subscription, module, action = 'VIEW') {
  if (subscription?.is_read_only && action !== 'VIEW' && action !== 'DOWNLOAD') return false
  if (!user || user.status !== 'ACTIVE' || !hasModuleAccess(subscription, module)) return false
  if (user.role?.is_super_admin) return true
  return user.permissions?.includes(`${module}.${action}`) ?? false
}
