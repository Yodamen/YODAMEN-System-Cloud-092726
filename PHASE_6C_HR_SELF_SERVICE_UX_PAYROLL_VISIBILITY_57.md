# YODAMEN Phase 6C — HR Self-Service UX & Payroll Visibility — Update 57

## Scope

This update refines the staff-facing HR experience without changing payroll history or database schema.

### 1. Standard Refresh Button
All currently active modules that expose a Refresh action now use the same small refresh icon immediately beside the module title. HR pages were aligned to the existing Operations/Sales/Admin pattern.

### 2. My Attendance becomes the self-service HR hub
`HR & Payroll → My Attendance` now has three tabs:
- Attendance
- Leave
- Day Off

The separate `My Leave & Day Off` sidebar item is removed. The legacy `/hr/my-leave` route redirects to `/hr/my-attendance?tab=leave`.

### 3. Attendance List / Calendar toggle
The List / Calendar selector now lives in the Attendance filter row together with the date/month controls.

### 4. Simplified current-attendance panel
The prior two-card layout was replaced with one compact panel showing:
- current status
- personnel name/code
- server time
- Time In
- Time Out
- worked hours
- proof + Time In/Out action only when an action is still available

This removes the redundant second status message after attendance has already been approved/recorded.

### 5. My Payroll visibility
My Payroll is visible only when the logged-in linked Personnel record has an operational compensation rule.

Operational compensation means:
- `HOURLY_PLUS_COMMISSION`, or
- `GUARANTEED_VS_COMMISSION`

`NONE` hides My Payroll from the sidebar and blocks direct route/API access.

The authenticated user payload now includes:
- linked Personnel ID/code/name
- compensation rule
- `has_operational_compensation`

### 6. Staff My Payroll redesign
The staff payroll page now follows the prior YODAMEN staff-payroll reference more closely:
- Today Confirmed Commission
- Today Projected Compensation
- Running Commission
- Projected Running Net
- Running Payroll breakdown
- Cash Advance summary
- Confirmed Commission Transactions
- Approved/Paid Payroll History and payslip detail

The page uses existing Laravel source records and does not create a second payroll calculation engine.

## Database
No migration is required.

## Install
From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6C-HR-SELF-SERVICE-UX-PAYROLL-VISIBILITY-57.zip -d .
```

Backend checks:

```bash
cd backend
php artisan test --filter=PayrollTest
php artisan test --filter=AttendanceTest
php artisan test --filter=LeaveManagementTest
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

After installation, refresh/reload the browser so `/api/v1/auth/me` reloads the new Personnel compensation eligibility into the session UI.

## Expected My Payroll behavior
- Linked Personnel with operational compensation: My Payroll is visible.
- Linked Personnel with compensation rule `NONE`: My Payroll is absent from sidebar and direct access redirects away; API also rejects the request.
- User not linked to Personnel: My Payroll is absent.

## Migration prerequisite
Updates 004000 and 005000 from the HR completion package must already be migrated for Cash Advance and Payroll screens to operate. Do not use `migrate:fresh`.
