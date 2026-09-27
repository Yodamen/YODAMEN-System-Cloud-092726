# YODAMEN Phase 6C — HR MySQL Migration Repair 56

## Purpose
Repairs pending HR migrations 004000 and 005000 when MySQL rejects Laravel's automatically generated index names for exceeding the 64-character identifier limit, and allows the migrations to safely resume after partial MySQL DDL.

## Changes
- Uses short explicit names for long indexes/unique keys.
- Adds `Schema::hasTable()` guards so a partially-created table does not block the migration retry.
- Adds `Schema::hasColumn()` guards for Payroll links added to Daily Compensation and Commission Earnings.
- No existing rows are deleted.
- No `migrate:fresh` is required.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6C-HR-MYSQL-MIGRATION-REPAIR-56.zip -d .
cd backend
php artisan migrate
php artisan migrate:status
```

Expected result:
- `2026_09_18_004000_create_employee_cash_advances_and_payroll_adjustments` = Ran
- `2026_09_18_005000_create_payroll_runs_and_lines` = Ran

Then run:

```bash
php artisan test --filter=PayrollTest
php artisan test --filter=CashAdvanceTest
```
