# YODAMEN Phase 7C — HR Controls Install Fix 77

This patch fixes three Update 75 installation defects:

1. Restores `PayrollRun::cashAdvanceWaivers()` so Payroll can serialize per-run cash-advance waivers.
2. Restores DomainException handling in Leave Management balance updates so paid-leave entitlement rejection returns HTTP 422 instead of HTTP 500.
3. Fixes Cash Advance write-off date handling. `BusinessClock::today()` already returns a `Y-m-d` string, so the service no longer calls `toDateString()` on it.

No migration is required.

## Install

Unzip over the YODAMEN project root, then run:

```bash
cd backend
php artisan test --filter=PayrollTest
php artisan test --filter=CashAdvanceTest
php artisan test --filter=LeaveManagementTest
```
