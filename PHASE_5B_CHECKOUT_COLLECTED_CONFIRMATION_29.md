# YODAMEN Phase 5B — Checkout Collected Confirmation 29

## Scope

This update corrects two current Phase 5B behaviors:

1. The Booking Advance Payment / payment collection card now spans the full Booking form width.
2. Existing Booking collections that were already applied as Interim to the package behind a claimed Service Credit must still be manually confirmed in the Checkout table's `Collected` column before Checkout can be finalized.

## Important financial behavior

The Checkout `Collected` allocation for a Booking-time Interim payment is a confirmation/mapping step only. It does not apply the cash to the package a second time.

Example:

- BKG-OLD package open balance = P5,500
- During BKG-NEW, P1,000 is collected and applied as Interim to BKG-OLD
- BKG-OLD immediately becomes P4,500 open
- BKG-NEW later claims a Service Credit from that same package
- At Checkout, the user must manually enter P1,000 in the Service Credit row under `Collected`
- Finalizing confirms the existing Booking allocation; the old package remains P4,500, not P3,500

## Checkout control

Checkout now exposes:

- `booking_interim_confirmation_required`
- per-line `booking_interim_confirmation_amount`

The frontend requires the full existing Booking collection that belongs to the current transaction context to be allocated manually in `Collected`.

The backend validates the same rule. Missing or short allocation is rejected.

## Service Credit classification

A Booking containing a Service Credit claim is classified using the payment applied by that Booking to the package that funded the claim:

- no package payment from this Booking -> Service Credit (when there are no chargeable lines)
- package payment + package balance remains -> On Account
- package payment + package balance becomes zero -> Paid, unless another chargeable line in the same Booking still remains On Account

This applies to:

- Booking-time Interim payment to the claimed package
- Advance applied at Checkout through the Service Credit row
- Checkout Payment applied through the Service Credit row

The Transactions row remains one row per Booking ID.

## Files replaced

- `frontend/src/components/operations/InitialPaymentCollection.jsx`
- `frontend/src/components/sales/CheckoutModal.jsx`
- `backend/app/Http/Requests/Api/V1/Sales/FinalizeCheckoutRequest.php`
- `backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php`
- `backend/app/Services/Sales/CheckoutSettlementService.php`
- `backend/tests/Feature/Packages/PackageServiceCreditTest.php`

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

A new backend test verifies that a Booking-time Interim payment must be manually confirmed at Checkout and is not applied to the package twice.
