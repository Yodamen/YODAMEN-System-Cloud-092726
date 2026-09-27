# YODAMEN Phase 5B — Transaction Booking Integrity 22

## Purpose
Correct Checkout and Transactions presentation so payment events, Booking references, and On Account balances remain tied to the correct Booking.

## Changes

1. Payment history now labels a CHECKOUT payment as `Checkout Payment` instead of `Final Payment`.
2. Payment history uses the application purpose when available, so a MIXED receipt applied to an older On Account line displays as `Interim` for that application.
3. Removed the `Existing On Account Balances` card from Checkout.
4. Checkout Payment now applies only to the current Booking's current Sale lines.
5. Interim payments already applied to older On Account bookings remain on those older bookings and are not reallocated during the current Booking's Checkout.
6. Transactions non-Pending tabs are grouped by Booking ID so one Booking is represented by one transaction row per tab.
7. Service Credit bookings are no longer moved to On Account merely because an Interim payment was collected during that Booking for an older balance.
8. Paid and Service Credit tabs only show rows whose parent Booking/Sale actually has that final status.
9. On Account payment amount is derived only from payment applications on that Booking's On Account lines.

## Migration
None.

## Backend validation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan optimize:clear
php artisan test
```

## Frontend validation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run lint
npm run build
npm run dev
```
