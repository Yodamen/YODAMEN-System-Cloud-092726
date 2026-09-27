# YODAMEN Update 78 — HR Test & Cash Advance Date Fix

## Purpose
Small follow-up to Updates 75/77.

## Fixes
1. Cash Advance serialization now safely handles ledger/issued dates whether Laravel returns a cast date object or a legacy/plain date string.
2. EmployeeCashAdvanceLedger explicitly retains its immutable_date cast for entry_date.
3. Payroll overlap regression test now expects the current backend validation message.

## Database
No migration required.

## Install
Extract into the YODAMEN project root and overwrite matching files.

## Test
- php artisan test --filter=CashAdvanceTest
- php artisan test --filter=PayrollTest
- php artisan test --filter=LeaveManagementTest
