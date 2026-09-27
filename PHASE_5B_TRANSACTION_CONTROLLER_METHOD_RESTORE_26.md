# YODAMEN Phase 5B — Transaction Controller Method Restore 26

## Purpose
Restore the complete `TransactionsController` after Update 25 accidentally omitted the private `receivedPaymentsForSale()` helper while `saleResource()` still called it.

## Root cause
All affected Checkout and Transactions endpoints failed with HTTP 500 because `TransactionsController::receivedPaymentsForSale()` was referenced but not defined.

## Scope
- Complete replacement: `backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php`
- No migration.
- No frontend files changed.
- Booking-per-row and package-history logic from Update 25 is preserved.

## Validation
Run `php artisan optimize:clear` and `php artisan test` after installation.
