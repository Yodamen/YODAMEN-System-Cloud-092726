# YODAMEN Phase 5B — Filters + Booking Payment Sync 36

## Scope

### Transactions module
Adds the following filters to Pending, On Account, Paid, and Service Credit tabs:
- Date From / Date To
- Staff Name
- Booking ID
- Package ID
- Customer Name
- Service Name
- Reset Filters

Filters are applied in the backend so the result set is filtered before it is rendered.

### Bookings module
Keeps the existing date/search filters and adds:
- Customer Name
- Service
- Source
- Payment

Payment filter options include Advance, Interim, Checkout Payment, Paid, On Account, Service Credit, Pending Checkout, and Unpaid.

### Completed Booking payment status
Booking API responses now expose a computed `payment_status` and `checkout_payment_total`.
The Completed tab Payment column follows the finalized Sale result:
- PAID -> Paid
- ON_ACCOUNT -> On Account
- SERVICE_CREDIT -> Service Credit
- Pending financial finalization -> Pending Checkout

This fixes completed Bookings incorrectly showing Unpaid simply because no Advance was collected at Booking creation.

## Files replaced
- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/app/Http/Controllers/Api/V1/Operations/BookingController.php
- backend/tests/Feature/Operations/BookingTest.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php
- frontend/src/pages/sales/TransactionsPage.jsx
- frontend/src/pages/operations/BookingsPage.jsx
- frontend/src/components/operations/PaymentIndicator.jsx
- frontend/src/index.css

## Migration
No database migration is required.

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
