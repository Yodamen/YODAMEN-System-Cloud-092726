# Phase 2A Installation

1. Confirm `development` branch and a clean Git working tree.
2. Stop Laravel and Vite dev servers.
3. Extract `YODAMEN-PHASE-2A-UPDATE.zip` into the project root.
4. Run `php artisan optimize:clear`.
5. Run `php artisan migrate:status` and confirm `2026_09_12_000300_create_customer_booking_tables` is Pending.
6. Run `php artisan migrate`.
7. Run `php artisan db:seed --class=MasterDataSeeder`. This safely fills only reference groups that are still empty.
8. Run `php artisan test`.
9. Run `npm run lint` and `npm run build`.
10. Start Laravel and Vite, then test Customers and Bookings.

Do not run `migrate:fresh`.
