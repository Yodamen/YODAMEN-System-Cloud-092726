# YODAMEN Phase 6C — My Payroll Running Projection Fix 58

## Purpose
Fixes My Payroll so APPROVED payroll is not counted again as running/projected payroll.

## Correct behavior
- DRAFT Payroll Line + DRAFT Payroll Run = may feed Running Payroll / Projected Running Net.
- APPROVED Payroll = historical finalized payroll, shown in Payroll History/Payslips, not in running projection.
- PAID Payroll = historical paid payroll, shown in Payroll History/Payslips, not in running projection.
- OPEN Daily Compensation and OPEN Payroll Adjustments can feed projections when there is no Draft payroll line.

## File replaced
- `backend/app/Services/Hr/PayrollService.php`

## Migration
None.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6C-MY-PAYROLL-RUNNING-PROJECTION-FIX-58.zip -d .
cd backend
php artisan test --filter=PayrollTest
```

Expected focused result: 4 Payroll tests pass.
