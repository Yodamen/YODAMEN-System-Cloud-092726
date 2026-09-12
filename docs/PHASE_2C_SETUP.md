# Phase 2C Local Installation

Apply this package only on the `development` branch after the approved Phase 2B checkpoint.

## Backend

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan optimize:clear
php artisan migrate:status
php artisan migrate
php artisan db:seed --class=ServicingPermissionSeeder
php artisan test
```

Expected new migration:

`2026_09_12_000500_create_visit_servicing_tables`

The Phase 2C package adds nine Servicing tests to the previous Phase 2B test set. The expected total on the supplied baseline is **40 passing tests**.

## Frontend

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run lint
npm run build
npm run dev
```

## Browser validation

1. Create/check in a reservation or Walk-In so it appears in Queuing.
2. Click **Start Servicing**.
3. Confirm exactly one `VIS-...` Visit is created and the Queue row moves to Servicing.
4. Open Operations → Servicing.
5. Confirm separate service lines retain their individual assigned staff.
6. Change an active service's staff assignment.
7. Complete one service while another remains active. Visit must remain `IN_SERVICE`.
8. Complete the last active service. Visit, Booking, and Queue must become `COMPLETED`.
9. Test a second Visit where all service lines are cancelled with reasons. Visit, Booking, and Queue must become `CANCELLED`.
10. Test mixed outcome: complete at least one service and cancel another. Visit must become `COMPLETED` while line-level history preserves both outcomes.
11. Add a NEW_SERVICE during an active Visit and confirm the historical Queue snapshot does not change.

## Expected phase boundary

Completing a service records an operational completion event only. It must not create a Sale, Payment, Commission, Service Credit settlement, or accounting entry in Phase 2C.
