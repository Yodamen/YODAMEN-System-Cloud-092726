# Phase 1A Change Manifest

## MODIFY EXISTING

- `backend/.env.example`
- `backend/app/Models/User.php`
- `backend/bootstrap/app.php`
- `backend/config/app.php`
- `backend/config/session.php`
- `backend/database/factories/UserFactory.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/routes/api.php`
- `backend/tests/Pest.php`
- `frontend/index.html`
- `frontend/public/favicon.svg`
- `frontend/src/App.jsx`
- `frontend/src/index.css`
- `frontend/src/main.jsx`

## ADD NEW

### Laravel

- `backend/config/cors.php`
- `backend/config/yodamen.php`
- `backend/app/Models/Business.php`
- `backend/app/Models/Branch.php`
- `backend/app/Models/Role.php`
- `backend/app/Models/Permission.php`
- `backend/app/Models/AuditLog.php`
- `backend/app/Models/CodeSequence.php`
- `backend/app/Models/IdempotencyRequest.php`
- `backend/app/Http/Middleware/RequestCorrelationId.php`
- `backend/app/Http/Middleware/RequirePermission.php`
- `backend/app/Http/Controllers/Api/V1/AuthController.php`
- `backend/app/Http/Controllers/Api/V1/BootstrapController.php`
- `backend/app/Http/Controllers/Api/V1/Admin/RolePermissionController.php`
- `backend/app/Http/Requests/Api/V1/Auth/LoginRequest.php`
- `backend/app/Http/Requests/Api/V1/Auth/ChangeAccessCodeRequest.php`
- `backend/app/Http/Requests/Api/V1/Admin/UpdateRolePermissionsRequest.php`
- `backend/app/Services/Foundation/BusinessClock.php`
- `backend/app/Services/Foundation/CodeSequenceService.php`
- `backend/app/Services/Foundation/IdempotencyService.php`
- `backend/app/Services/Security/AuditService.php`
- `backend/app/Services/Security/RolePermissionService.php`
- `backend/database/migrations/2026_09_11_000100_create_yodamen_foundation_tables.php`
- `backend/database/migrations/2026_09_11_000110_extend_users_for_yodamen.php`
- `backend/database/seeders/FoundationSeeder.php`
- `backend/database/seeders/InitialSuperAdminSeeder.php`
- `backend/tests/Support/Foundation.php`
- `backend/tests/Feature/Foundation/HealthTest.php`
- `backend/tests/Feature/Foundation/AuthenticationTest.php`
- `backend/tests/Feature/Foundation/PermissionsTest.php`
- `backend/tests/Feature/Foundation/BusinessClockTest.php`
- `backend/tests/Feature/Foundation/InfrastructureTest.php`

### React

- `frontend/.env.example`
- `frontend/src/api/client.js`
- `frontend/src/context/AuthContext.jsx`
- `frontend/src/features/auth/ProtectedRoute.jsx`
- `frontend/src/features/auth/AccessCodeResetModal.jsx`
- `frontend/src/components/navigation/navigationConfig.js`
- `frontend/src/components/navigation/Sidebar.jsx`
- `frontend/src/layouts/AppLayout.jsx`
- `frontend/src/pages/LoginPage.jsx`
- `frontend/src/pages/DashboardPage.jsx`
- `frontend/src/router/index.jsx`

### Continuity documentation

- `docs/YODAMEN_BUSINESS_RULES.md`
- `docs/PARITY_MATRIX.md`
- `docs/TRANSACTION_WATERFALL.md`
- `docs/MIGRATION_STATUS.md`
- `docs/LEGACY_SOURCE_MAP.md`
- `docs/PHASE_1A_SETUP.md`
- `docs/PHASE_1A_CHANGE_MANIFEST.md`

## REMOVE TEMPLATE-ONLY FILES

- `backend/tests/Feature/ExampleTest.php`
- `backend/tests/Unit/ExampleTest.php`
- `frontend/src/App.css`

These contain only the original framework/template examples and no YODAMEN business behavior.

## DO NOT CHANGE IN PHASE 1A

- No Booking, Queue, Visit or Servicing business schema/actions.
- No Sales, Payment, Payment Application, Package, Service Credit or Commission schema/actions.
- No Attendance, Daily Compensation, Cash Advance, Payroll or Profit Sharing schema/actions.
- No historical Google Sheets data import.
- No removal of Laravel's separate default backend frontend/build setup beyond YODAMEN's independent React source changes.
- No new React state/query library.
