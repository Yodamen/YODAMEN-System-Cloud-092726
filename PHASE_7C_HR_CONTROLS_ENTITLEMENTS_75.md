# YODAMEN Update 75 — HR Controls & Entitlements

## Scope
This update strengthens the existing HR/Payroll module without rebuilding it.

### Personnel / Leave
- Adds **Paid Leave Entitled** to Personnel.
- Existing personnel default to entitled to preserve prior behavior.
- Approved leave snapshots whether the leave is paid for that employee.
- Personnel who are not entitled to paid leave do not consume paid-leave credits and do not receive paid-leave compensation.
- Compensation terms become effective-dated so later changes do not rewrite historical payroll.

### Work Schedule / Day Off
- Personnel Work Schedule remains the permanent regular weekly schedule.
- Staff-filed Day Off remains a date-specific approved override only; it does not modify the regular weekly schedule.

### Attendance / OT
- Early Time In does not count as OT.
- OT for OT-entitled staff starts only after the scheduled end time.
- Attendance duration sanity checks reject unrealistic shifts over 24 hours.

### Payroll controls
- Blocks overlapping payroll periods in the same payroll scope.
- Prevents negative net pay.
- Adds payroll pre-approval exception checks for unresolved HR/payroll source issues.
- Keeps approved/paid payroll immutable.

### Cash Advance controls
- Adds Manager-only, per-payroll-run **Waive Deduction** with mandatory reason.
- A waiver skips only that payroll run; it does not forgive the debt and does not double the next scheduled deduction.
- Cash advance deductions remain capped by scheduled deduction, remaining balance, and payroll amount available before the CA deduction.
- Permanent Cash Advance forgiveness/write-off is separate and Super Admin-only with mandatory reason.

## Installation
From the project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-7C-HR-CONTROLS-ENTITLEMENTS-75.zip -d .
cd backend
php artisan migrate
```

Do not use `migrate:fresh`.

## Focused tests
```bash
php artisan test --filter=PayrollTest
php artisan test --filter=CashAdvanceTest
php artisan test --filter=LeaveManagementTest
php artisan test --filter=AttendanceTest
php artisan test --filter=DailyCompensationTest
```

Then:
```bash
php artisan test
cd ../frontend
npm run build
```
