# Install Update 79 on macOS

1. Stop the Laravel and Vite dev servers.
2. Back up the project and database before replacing files.
3. Copy the contents of this package into the root of `YODAMEN-System-Development`, preserving folders and replacing matching files.
4. From `backend`, run:
   `php artisan optimize:clear`
5. Still from `backend`, run:
   `php artisan migrate`
6. Confirm migrations:
   `php artisan migrate:status`
7. Run the focused backend tests:
   `php artisan test --filter=PayrollTest`
   `php artisan test --filter=LeaveManagementTest`
   `php artisan test --filter=AttendanceTest`
   `php artisan test --filter=RetailProduct`
   `php artisan test --filter=RetailPos`
8. From `frontend`, run `npm install` if dependencies need refresh, then `npm run build`.
9. Restart Laravel and Vite and hard-refresh the browser.

Acceptance checks:
- OT multiplier accepts 0.50, 0.75 and other positive decimals below 1.
- Leave tab is absent for personnel whose paid leave entitlement is disabled; Day Off remains accessible.
- Day Off opens on the weekly schedule and Reschedule opens the request form.
- Sales menu order: Dashboard, Transaction, Retail POS, Z-Reading.
- Sales Dashboard and Z-Reading pages open instead of appearing disabled.
- Product modal no longer shows the two requested helper lines.
- Masterlist filter bar has a shorter Search field with Apply and Reset inline.
- Successful Product Masterlist loads do not retain a stale "Unable to load products" banner.
