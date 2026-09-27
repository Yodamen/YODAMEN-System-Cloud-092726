# YODAMEN Update 73 — System UI, Payroll Adjustment, Filters & Action Menus

## Scope
This update is a UI/behavior refinement only. No database migration is required.

### System-wide UI standards
- Table cells are top-aligned.
- All visible Action columns use the standard Actions dropdown with black text.
- Filter Apply buttons use the same compact height as filter fields.
- Filter bars that use Apply now also include Reset.
- Section/subheadline copy is smaller and more compact.

### HR & Payroll
- Attendance filters use a more compact layout, with Apply + Reset beside the filters.
- My Attendance Apply / Reset / List / Calendar / Time In-Out controls use a sleeker compact height.
- Commission Summary, Daily Compensation, Leave Management, Cash Advances, and Payslips add Reset beside Apply.
- Leave Balances button shortened to `View`.
- Payroll Adjustment modal:
  - adds Add / Deduct selector;
  - amount is entered as a positive value and the system applies the sign automatically;
  - removes the separate Reason field;
  - Description spans the full width;
  - buttons are compact and aligned right.
- Create Payroll Draft Notes stays full width, with buttons below and aligned right.
- Payroll, Payslips, My Payroll, Cash Advances, Attendance, Leave, Commission Rules, Discount Rules, Product Masterlist, and Transactions action cells use the standard Actions dropdown.

### Cash Advance deduction control
No new calculation rule was needed because the current Payroll service already uses:

`Actual deduction = MIN(scheduled deduction, outstanding cash-advance balance, available payroll net before CA deduction)`

A regression test was added to protect this rule.

### Retail POS History filters
Added:
- Search
- From / To
- Customer
- Assigned Staff
- Status
- Apply / Reset

Backend history filtering was expanded to match.

## Install
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-SYSTEM-UI-PAYROLL-FILTERS-73.zip -d .
```

No migration is required.

## Recommended tests

```bash
cd backend
php artisan test --filter=PayrollTest
php artisan test --filter=RetailPosTest
php artisan test --filter=AttendanceTest
php artisan test --filter=LeaveManagementTest
```

Then:

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```
