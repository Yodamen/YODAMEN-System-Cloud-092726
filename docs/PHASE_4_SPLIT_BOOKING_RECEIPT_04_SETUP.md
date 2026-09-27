# Phase 4 Split Booking Receipt 04 Setup

1. Stop Laravel and Vite.
2. Extract the update ZIP into the YODAMEN project root.
3. From `backend/`, run `php artisan optimize:clear`.
4. Run `php artisan migrate:status` and confirm `2026_09_13_000720_support_split_booking_payment_allocations` is Pending.
5. Run `php artisan migrate`.
6. Run `php artisan test`.
7. From `frontend/`, run `npm run lint` and `npm run build`.
8. Start Laravel and Vite and test both Reservation Booking and Walk-In split receipts.

## Required test scenarios

- No On Account balance: full receipt becomes Advance Reserve for the new Booking/Walk-In.
- Existing On Account balance: full receipt may be Interim only.
- Existing On Account balance: one receipt may be split between Advance and Interim.
- Allocation total must equal Amount Received exactly.
- Interim allocation immediately reduces the older On Account line.
- Advance allocation remains available only to the future Sale of the new Booking/Walk-In.
- Completing the new Visit must create a Sale whose pending collected amount includes only the Advance component, never the Interim component.
