# YODAMEN HR Completion Bundle — Updates 51 to 54

This combined patch contains the remaining employee HR & Payroll modules:

- Leave & Day Off filing and approval
- Leave balances and schedule overrides
- Commission Earnings posting foundation
- Daily Compensation
- Employee Cash Advances and Payroll Adjustments
- Payroll Runs and Payroll Lines
- Payslips
- My Payroll

It assumes Attendance/Work Schedule and Commission Rules are already installed from the earlier YODAMEN updates.

## Install from Downloads

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-HR-COMPLETION-51-54.zip -d .

cd backend
php artisan migrate
php artisan db:seed --class=HrLeaveSeeder
php artisan db:seed --class=HrCompletionPermissionSeeder
```

Do not use `migrate:fresh`.

## Focused tests

```bash
php artisan test --filter=LeaveManagementTest
php artisan test --filter=DailyCompensationTest
php artisan test --filter=CommissionRuleTest
php artisan test --filter=CashAdvanceTest
php artisan test --filter=PayrollTest
```

Then full regression:

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Payroll lifecycle

`DRAFT -> APPROVED -> PAID`

Draft payroll can be recalculated while preserving the same Payroll Run ID. Approved/Paid payroll cannot be recalculated. Corrections after approval must use future payroll adjustments rather than rewriting historical payroll.
