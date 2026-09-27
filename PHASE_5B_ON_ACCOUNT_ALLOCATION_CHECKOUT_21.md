# YODAMEN Phase 5B — On Account Allocation + Checkout Continuity 21

## Purpose
Correct two Phase 5B settlement issues:

1. On Account Payment amounts must be line-specific. Payments applied to unrelated services in the same Booking must not inflate a multi-session package / On Account service amount.
2. Checkout must support a final collection even when the current Booking has no chargeable line, as long as the customer has an existing On Account balance that the Checkout Payment can validly settle.

## Rules implemented

- On Account rows use Payment Applications actually applied to that Sale Item.
- A Service Credit transaction displayed under On Account uses only the source Booking's allocations to On Account balances, excluding unrelated Advance/current-service payments.
- Checkout exposes existing On Account balances for the same customer.
- Checkout Payment can be allocated to the current Booking, existing On Account lines, or both in one Checkout receipt.
- The Checkout receipt remains linked to the current Booking ID as collection context.
- Payments applied to an older On Account line retain that older Sale/Package lineage through Payment Applications.
- An Interim payment made earlier and a Checkout payment made later can coexist under the same Booking.
- No unapplied/floating cash is created: Amount Collected Now must equal current-sale Checkout allocations plus existing On Account allocations.
- Single-session/current product full-payment rules remain unchanged.
- Multi-session packages may continue carrying balances.

## Migration
None.

## Complete replacement files

- backend/app/Services/Sales/CheckoutSettlementService.php
- backend/app/Services/Sales/PaymentLedgerService.php
- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/app/Http/Requests/Api/V1/Sales/FinalizeCheckoutRequest.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php
- frontend/src/components/sales/CheckoutModal.jsx
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
