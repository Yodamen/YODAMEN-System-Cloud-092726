# YODAMEN Phase 5B — Booking Payment History Integrity 25

## Purpose
Keep the Transactions module strictly Booking-based while preserving package payment lineage.

## Rules implemented

1. Every finalized Transactions row represents exactly one Booking ID / parent Sale.
2. Frontend no longer consolidates multiple Sale Item rows into one Booking row. The backend now returns the Booking row directly.
3. Payment shown in the row is the payment allocation attributable to that Booking context:
   - On Account: only amounts applied to the multi-session package / On Account lines of that Booking. Payments from the same receipt that settled older or unrelated services do not inflate the row amount.
   - Paid / Service Credit: allocations collected under that Booking are retained as that Booking's payment context.
4. Clicking Payment opens Payment Details with two separate areas:
   - This Booking — payment allocations collected under this Booking ID.
   - Earlier Package Payments — historical payments on the package(s) referenced by this Booking that occurred before the current Booking.
5. Historical package payments are not merged into the amount displayed on the current Booking row.
6. Paid / On Account / Service Credit tab membership continues to use the parent Sale status, so a fully settled parent Booking appears in Paid.
7. No migration is required.

## Complete replacement files

- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/app/Services/Sales/CheckoutSettlementService.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php
- frontend/src/pages/sales/TransactionsPage.jsx

## Validation

Backend:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan optimize:clear
php artisan test
```

Frontend:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run lint
npm run build
npm run dev
```
