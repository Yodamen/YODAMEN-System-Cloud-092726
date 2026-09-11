# YODAMEN Phase 2A Hotfix 02

## Purpose
Strengthen Customer duplicate detection before Phase 2A is committed.

## Rules
A customer create or edit is blocked when either condition matches another customer in the same business:

1. The normalized mobile number already exists.
2. The normalized First Name + Last Name + exact Birthday already exists.

Name normalization trims leading/trailing spaces, collapses repeated internal spaces, and compares case-insensitively.

Allowed examples:
- Same name with a different birthday and different mobile number.
- Same birthday with a different name and different mobile number.

## Files
- `backend/app/Services/Operations/CustomerService.php`
- `backend/tests/Feature/Operations/CustomerTest.php`
- `frontend/src/pages/operations/CustomersPage.jsx`
- `docs/PHASE_2A_CHANGE_MANIFEST.md`

## Database
No migration or seeder is required. The existing normalized-mobile database uniqueness remains in place. The Name + Birthday control is enforced server-side by the Customer service.

## Validation
Run:

```bash
cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run lint
npm run build
```

Expected backend test count after this hotfix: 25 passing tests.
