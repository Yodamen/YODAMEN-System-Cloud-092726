# YODAMEN Update 100G1
## Effective Collections Regression Test Fix

### Fix
Sales Dashboard / Z-Reading effective collections now resolves a transaction's current Refund Due directly from the Sales table using the payment's Sale ID or Booking ID.

This preserves the immutable RECEIVED Payment ledger while excluding correction-generated Refund Due from effective Payments Received and payment-method totals.

Example covered by the regression test:
- immutable received payment: 800
- approved correction refund due: 300
- effective dashboard collections: 500

### Files
- backend/app/Services/Sales/SalesReportingService.php

### Migration
No database migration.

### Verification
Run:

php artisan optimize:clear
php artisan test --filter="excludes correction refund due from effective dashboard collections while preserving the received payment"
php artisan test
