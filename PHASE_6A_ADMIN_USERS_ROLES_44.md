# YODAMEN Phase 6A — Admin Users & Roles Update 44

## Adds
- Admin → Users & Roles page
- User create/edit, Personnel link, one Role per User, default branch, activate/deactivate
- Access Code reset with force-reset option
- Custom Role create/edit/status
- Role permission matrix using the existing permission catalog
- Last-active-Super-Admin lockout protection
- Active-user protection before role deactivation
- Audit logging for user/role/access changes

## Database
No migration required.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6A-ADMIN-USERS-ROLES-44.zip -d .
cd backend
php artisan test --filter=UserRoleAdministrationTest
php artisan test --filter=PermissionsTest
cd ../frontend
npm run build
```

## Notes
- Users are disabled rather than deleted to preserve history.
- Personnel and User remain separate records linked one-to-one.
- Super Admin permissions cannot be restricted by the role matrix.
