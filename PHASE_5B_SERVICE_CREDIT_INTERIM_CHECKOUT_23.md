# YODAMEN Phase 5B - Service Credit Interim Checkout 23

## Purpose

1. A Service Credit claim whose source package still has an On Account balance can accept an editable payment amount during Checkout.
2. The amount entered on that Service Credit row is applied as an Interim payment to the original package purchase Sale Item, not as a new charge on the redeemed service.
3. A single Checkout receipt can therefore contain both current-Booking Checkout allocation and Interim allocation to the package behind a Service Credit claim.
4. Transaction rows remain strictly per Booking ID.
5. When an original On Account Booking reaches zero outstanding balance, its transaction status moves to Paid automatically.
6. Package entitlement remains independent from financial settlement. A fully paid package can remain ACTIVE while unused sessions remain.

## Files replaced

- backend/app/Services/Sales/CheckoutSettlementService.php
- backend/app/Services/Sales/PaymentLedgerService.php
- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/tests/Feature/Packages/PackageServiceCreditTest.php
- frontend/src/components/sales/CheckoutModal.jsx

## Migration

No migration required.
