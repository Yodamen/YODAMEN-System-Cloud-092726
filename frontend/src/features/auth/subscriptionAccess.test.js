import test from 'node:test'
import assert from 'node:assert/strict'
import { canPerform, hasModuleAccess } from './subscriptionAccess.js'

const admin = { status: 'ACTIVE', role: { is_super_admin: true } }
test('Super Admin role cannot bypass a missing plan feature', () => {
  assert.equal(canPerform(admin, { modules: ['customers'] }, 'payroll'), false)
  assert.equal(canPerform(admin, { modules: ['customers'] }, 'customers'), true)
})
test('subscribed features still require the employee action permission', () => {
  const employee = { status: 'ACTIVE', permissions: ['sales.VIEW'] }
  const subscription = { modules: ['sales', 'payroll'] }
  assert.equal(canPerform(employee, subscription, 'payroll'), false)
  assert.equal(canPerform(employee, subscription, 'sales'), true)
  assert.equal(canPerform(employee, subscription, 'sales', 'EDIT'), false)
})
test('exhausted trials and invalid subscriptions allow viewing but prevent mutations', () => {
  const subscription = { modules: ['sales'], is_read_only: true }
  assert.equal(canPerform(admin, subscription, 'sales', 'VIEW'), true)
  assert.equal(canPerform(admin, subscription, 'sales', 'EDIT'), false)
  assert.equal(canPerform(admin, subscription, 'sales', 'CREATE'), false)
})
test('missing subscription data and inactive sessions fail closed', () => {
  assert.equal(hasModuleAccess(null, 'sales'), false)
  assert.equal(canPerform(admin, undefined, 'sales'), false)
  assert.equal(canPerform({ ...admin, status: 'INACTIVE' }, { modules: ['sales'] }, 'sales'), false)
})
