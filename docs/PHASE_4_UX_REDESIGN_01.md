# YODAMEN Phase 4 UX / On Account Redesign 01

## What changes

- Checkout removes the duplicate `Amount Collected Now` field.
- `Payment Now` is derived from the service-line allocation inputs.
- Existing Advance is auto-preallocated and remains editable.
- On Account rows no longer have a `Collect` action.
- `Receive Interim Payment` works at customer level and loads every open On Account Booking for that customer.
- One Interim Payment receipt can be distributed across multiple open Booking/service balances.
- The ledger still stores this receipt internally as `COLLECTION` for approved accounting parity.

## Database change

Migration `2026_09_13_000710_allow_customer_interim_payments.php` makes `payments.booking_id` nullable so one customer receipt can settle obligations belonging to multiple Bookings. Each Payment Application retains the exact target Sale/Sale Item and therefore Booking ID.

## Validation

Run:

```bash
php artisan optimize:clear
php artisan migrate
php artisan test
```

Then:

```bash
npm run lint
npm run build
npm run dev
```

## Browser tests

1. Pending Sale with one service and no Advance: enter Payment Now on the line, choose method, Finalize.
2. Pending Sale with Advance: Advance should be preallocated. Enter only any additional Payment Now.
3. Leave a balance and explicitly allow On Account.
4. Open On Account tab. Confirm there is no row-level Collect button.
5. Click `Receive Interim Payment`.
6. Select customer. Confirm all open On Account Booking IDs appear.
7. Allocate one receipt across one or more service lines/bookings.
8. Confirm paid lines move to Paid and remaining balances stay On Account.
