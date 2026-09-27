# YODAMEN Phase 6C — Payroll, Payslips & My Payroll — Update 54

## Scope
- Payroll Runs: DRAFT -> APPROVED -> PAID, with optional CANCELLED while Draft.
- Draft recalculation reuses the same Payroll Run ID and releases/rebuilds Draft reservations.
- Payroll Lines snapshot personnel name/position and compensation totals.
- Open Payroll Adjustments are included by effective date.
- Active employee Cash Advances reserve deductions in Draft and post ledger deductions only on Approval.
- Approved/Paid payroll history is frozen from recalculation.
- Attendance tied to approved payroll is payroll-locked.
- Payslips show Approved/Paid payroll lines.
- My Payroll is strictly scoped to the logged-in linked Personnel record.

## Install
From `/Users/domalonzo/Documents/YODAMEN-System-Development`:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6C-PAYROLL-PAYSLIPS-MY-PAYROLL-54.zip -d .
cd backend
php artisan migrate
php artisan db:seed --class=HrCompletionPermissionSeeder
php artisan test --filter=PayrollTest
cd ../frontend
npm run build
```

Do not use `migrate:fresh`.
