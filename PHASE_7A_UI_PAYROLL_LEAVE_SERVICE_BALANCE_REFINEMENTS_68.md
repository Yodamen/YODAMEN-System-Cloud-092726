# YODAMEN Update 68 — UI, Payroll, Leave & Package Payment Refinements

## Summary

This update contains the requested UI and behavior refinements after Update 67.

### Retail POS — Retail Sales History
- Item column now shows **Product Name** as the headline.
- SKU is shown as the subheadline.
- New **Qty** column shows quantity per retail line.
- Payment column now shows **Amount** as the headline and **Payment Method** as the subheadline.
- Split payments are displayed as stacked payment entries.

### Unified tab layout
The current top-level tabbed modules now use the same pill-style tab layout already used by Bookings and Transactions:
- Retail POS
- Users & Roles
- My Attendance (Attendance / Leave / Day Off)

The List / Calendar selector inside My Attendance remains a compact view toggle because it is a display-mode selector, not a module tab.

### Payroll
- Fixes MySQL error: `Unknown column 'active' in 'where clause'` for the `branches` table.
- Payroll now loads branches using `branches.status = ACTIVE`.
- Restores selectable branches in **Create Payroll Draft**.
- Reorders page actions as **Payroll Draft** then **Adjustment**.
- Removes the `+` prefix from both buttons.
- Improves Create Payroll Draft modal:
  - Notes field uses the full modal width.
  - Cancel and Create & Calculate sit directly below Notes.
  - Buttons use normal compact button height.
- Adds a regression test for active branch loading.

### Leave Management
- Approval / rejection notes no longer use the browser native prompt.
- Uses the standard YODAMEN branded modal instead.
- Leave Balances no longer lists every employee balance on the main page.
- Admin selects an employee first, then opens a modal to view that employee's leave balances.
- Balance editing is done within the same employee balance workflow.

### Service Balances — Package Payment Monitoring
- Payment History is now ordered **latest transaction first**.

## Files Changed

### Backend
- `backend/app/Services/Hr/PayrollService.php`
- `backend/app/Services/Packages/ServiceBalanceReadService.php`
- `backend/tests/Feature/Hr/PayrollTest.php`

### Frontend
- `frontend/src/pages/sales/RetailPosPage.jsx`
- `frontend/src/pages/admin/UsersRolesPage.jsx`
- `frontend/src/pages/hr/MyAttendancePage.jsx`
- `frontend/src/pages/hr/PayrollPage.jsx`
- `frontend/src/pages/hr/LeaveManagementPage.jsx`
- `frontend/src/index.css`

## Database
No migration required.

## Install

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-UI-PAYROLL-LEAVE-SERVICE-BALANCE-REFINEMENTS-68.zip -d .
```

Then test:

```bash
cd backend
php artisan test --filter=PayrollTest
php artisan test --filter=LeaveManagementTest
php artisan test --filter=PackageServiceCreditTest
```

Then full backend regression:

```bash
php artisan test
```

Then frontend build:

```bash
cd ../frontend
npm run build
```

## Expected Payroll Result
`GET /api/v1/hr/payroll` should return active branch options, including the current YODAMEN branch, without querying a non-existent `branches.active` column.
