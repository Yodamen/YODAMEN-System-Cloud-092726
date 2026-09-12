# Phase 2B Local Setup

From backend:

```bash
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=OperationsPermissionSeeder
php artisan test
```

From frontend:

```bash
npm run lint
npm run build
npm run dev
```

Browser validation:
1. Create or use an active customer and service.
2. Check in a BOOKED reservation from Operations → Bookings.
3. Confirm exactly one QUE code appears in Operations → Queuing.
4. Click Check In again only if still visible; no duplicate Queue record should be created.
5. Create a Walk-In from Queuing. Confirm one BKG and one QUE are created.
6. Confirm assigned staff/service snapshots appear in Queuing.
7. Cancel one waiting Queue entry with a reason; Booking and Queue should both show Cancelled history.
8. Confirm no Advance Payment, Service Credit, or Start Servicing action is active yet.
