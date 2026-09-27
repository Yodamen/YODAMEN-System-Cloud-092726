# YODAMEN Phase 5B - Dual DevReset 19

## Purpose
Adds two independent development reset functions:

1. **Full DevReset** - clears all customer/transaction development data.
2. **Test-Only DevReset** - clears only customers explicitly marked **Test Customer** and their linked operational/financial transactions.

## Test Customer marker
- New database field: `customers.is_test_customer`
- Default: `false`
- Visible only to Super Admin during customer creation.
- Available in both Customers > Add Customer and the Quick Add Customer modal used by Booking/Walk-In.
- Classification is fixed after creation to reduce the risk of accidentally marking a real customer for deletion.
- Customer list displays `Test Customer` beneath the customer code when applicable.
- Backend independently enforces that only Super Admin can create a Test Customer.

## Reset commands

### Full reset
```bash
php artisan yodamen:devreset preview
php artisan yodamen:devreset arm
php artisan yodamen:devreset execute
```
Cancel an armed full reset:
```bash
php artisan yodamen:devreset cancel
```

### Test-only reset
```bash
php artisan yodamen:devreset-test preview
php artisan yodamen:devreset-test arm
php artisan yodamen:devreset-test execute
```
Cancel an armed test-only reset:
```bash
php artisan yodamen:devreset-test cancel
```

Both reset commands:
- only run in `local` or `development`
- require an active Super Admin username and Access Code before arming
- expire after 5 minutes
- require Access Code verification again before execution
- preserve master data, code sequences, and migration history

The test-only reset additionally preserves real customers, their transactions, audit logs, and idempotency records.

## Migration required
Yes.

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan migrate:status
php artisan migrate
php artisan optimize:clear
php artisan test
```

Never use `php artisan migrate:fresh`.

## Frontend validation
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run lint
npm run build
npm run dev
```

## Complete replacement / new files included
- `backend/app/Console/Commands/DevelopmentReset.php`
- `backend/app/Console/Commands/DevelopmentTestReset.php`
- `backend/app/Services/Development/DevelopmentResetService.php`
- `backend/app/Models/Customer.php`
- `backend/app/Http/Requests/Api/V1/Operations/SaveCustomerRequest.php`
- `backend/app/Http/Controllers/Api/V1/Operations/CustomerController.php`
- `backend/app/Services/Operations/CustomerService.php`
- `backend/database/migrations/2026_09_15_001900_add_is_test_customer_to_customers.php`
- `backend/tests/Feature/Foundation/DevelopmentResetServiceTest.php`
- `frontend/src/pages/operations/CustomersPage.jsx`
- `frontend/src/components/operations/QuickCustomerModal.jsx`
