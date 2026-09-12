# YODAMEN Phase 3A Setup

Phase 3A activates Sales Core only: Sale drafts, Sale Items, Advance Payment ledger, Advance linking, and Pending Transactions.

## Before installation

Phase 2C should be locally validated and committed on `development` before installing Phase 3A. Keep `stable/approved` aligned to the last accepted checkpoint.

## Install

1. Apply the Phase 3A update files.
2. Backend:

```bash
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=SalesCorePermissionSeeder
php artisan yodamen:sales-sync
php artisan test
```

3. Frontend:

```bash
npm run lint
npm run build
npm run dev
```

4. Run Laravel:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

## Payment Method prerequisite

Advance Payment requires an active value under Administration -> Reference Lists -> Payment Method. If the group is empty, add the actual methods YODAMEN uses before testing advances. Do not invent historical methods solely for migration.

## Browser validation

- New Booking can optionally record an Advance Payment.
- New Walk-In can optionally record an Advance Payment.
- Rebooking preserves the original Advance Payment.
- Service completion creates one Sale draft only.
- Sales -> Transactions becomes active.
- Pending shows one parent row per Sale/Booking.
- Checkout detail shows line items and received Advance Payments.
- Checkout finalization remains disabled until Phase 4.

## Do not run

Do not use `migrate:fresh`. Do not manually edit payments or sales rows.
