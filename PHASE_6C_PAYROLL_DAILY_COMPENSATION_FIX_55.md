# YODAMEN Phase 6C Payroll / Daily Compensation Fix 55

## Purpose
Fixes the PayrollTest failure caused by Eloquent resolving `DailyCompensation` to the singular table name `daily_compensation` while the migration creates `daily_compensations`.

## Changes
1. `backend/app/Models/DailyCompensation.php`
   - Explicitly maps the model to `daily_compensations`.
2. `backend/app/Services/Hr/PayrollService.php`
   - Corrects Draft payroll recalculation order:
     - release current Draft reservations
     - remove Draft payroll lines/allocations
     - rebuild OPEN Daily Compensation from current HR source records
     - rebuild the same Payroll Run and reserve sources again
   - Approved/Paid payroll remains immutable.
3. `backend/tests/Feature/Hr/PayrollTest.php`
   - Uses an actual approved Attendance record plus Personnel Work Schedule as the payroll source.
   - Removes the synthetic Daily Compensation fixture that was expected to survive a source rebuild.

## Database
No migration is required.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6C-PAYROLL-DAILY-COMPENSATION-FIX-55.zip -d .
```

Then:

```bash
cd backend
php artisan test --filter=PayrollTest
```

If passing:

```bash
php artisan test --filter=DailyCompensationTest
php artisan test --filter=CashAdvanceTest
php artisan test --filter=AttendanceTest
php artisan test
```
