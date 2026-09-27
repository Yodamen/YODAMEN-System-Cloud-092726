# YODAMEN 100K7N13B — Payroll Recovery Test Timeline Fix

This is a test-only correction on top of 100K7N13 + 100K7N13A.

## Why
The operational-correction stale-draft regression test created its synthetic POSTED correction at `run.calculated_at + 1 second`. Because that timestamp could be in the future relative to the test clock, a real Recalculate performed immediately afterward correctly still considered the future-dated correction newer than the recalculation.

The fixture now uses a real chronological sequence:
1. prior payroll calculation
2. posted operational correction
3. payroll Recalculate
4. stale exception clears
5. payroll can be approved

No production code, database schema, accounting behavior, commission logic, or statutory payroll logic is changed.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N13B-PAYROLL-RECOVERY-TEST-TIMELINE-FIX.zip -d .

cd backend
php artisan optimize:clear
php artisan test tests/Feature/Hr/PayrollRecoveryTest.php
php artisan test
```

## Expected
- `PayrollRecoveryTest`: 4 passed
- Full suite: use the actual result as the new baseline

100K7O remains ON HOLD.
