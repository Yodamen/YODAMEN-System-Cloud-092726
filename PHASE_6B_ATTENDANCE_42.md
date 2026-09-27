# YODAMEN Phase 6B — Attendance Update 42

## Purpose

Adds the React/Laravel/MySQL Attendance foundation for YODAMEN without changing Discount Updates 40–41, Service Credit Fix 39, Sales settlement, or Commission Rules.

This update activates:

- **HR & Payroll → My Attendance**
- **HR & Payroll → Attendance**

## Implemented business rules

### My Attendance

- Staff Time In / Time Out is self-service only.
- Staff can act only on the Personnel record linked to their logged-in User account.
- Photo proof is mandatory for both Time In and Time Out.
- Accepted proof types: JPG, PNG, WebP.
- Maximum proof size: 2 MB.
- Server time is authoritative. Staff cannot type or backdate their own timestamps.
- Time In creates `WORKING` + `PENDING` attendance.
- Time Out changes it to `COMPLETED` + `PENDING`.
- Completed attendance does not become payable attendance until Admin approval or adjustment.
- A user cannot create another Time In while an older `WORKING` attendance is still open.

### Admin Attendance

Dashboard cards:

- Timed In Today
- Currently Working
- Awaiting Approval
- Missing Time Out

Filters:

- From
- To
- Employee
- Status

Admin actions:

- View private Time In / Time Out proof
- Approve completed attendance
- Adjust Time In / Time Out
- Adjust OT hours when necessary

Adjustments require a mandatory reason. The original Time In and Time Out timestamps are preserved on the first correction and are never silently overwritten.

### Workflow

`WORKING` → `COMPLETED` → `APPROVED`

Admin correction produces:

`ADJUSTED`

Attendance payroll status remains `PENDING` until approval/adjustment, then becomes `PRESENT`.

### Payroll protection boundary

Attendance includes `payroll_locked_at` as the future payroll lock boundary. Once populated by a later Payroll phase, direct Attendance approval/correction is blocked and the system instructs the user to use the payroll adjustment workflow.

### OT

For this Attendance phase only, automatic OT hours follow the legacy baseline:

- if Personnel is not OT-entitled: `0`
- if OT-entitled: worked time above 8 hours is treated as OT

The configured Personnel OT multiplier is **not applied in Attendance**. Monetary OT computation belongs to Daily Compensation in the next HR phase.

## Private proof storage

Proof files use Laravel's private `local` storage disk. No public storage link is required.

Logical path:

`attendance-proofs / YYYY / MM / Display Name (EMP-ID) / Attendance ID / ...`

Proof is served only through an authenticated Admin Attendance API route with `attendance.VIEW` permission.

## Files

### ADD NEW

- `backend/database/migrations/2026_09_18_000300_create_attendance_tables.php`
- `backend/database/seeders/AttendancePermissionSeeder.php`
- `backend/app/Models/Attendance.php`
- `backend/app/Models/AttendanceProof.php`
- `backend/app/Services/Hr/AttendanceService.php`
- `backend/app/Http/Controllers/Api/V1/Hr/AttendanceController.php`
- `backend/app/Http/Controllers/Api/V1/Hr/MyAttendanceController.php`
- `backend/app/Http/Requests/Api/V1/Hr/AttendancePhotoRequest.php`
- `backend/app/Http/Requests/Api/V1/Hr/AdjustAttendanceRequest.php`
- `backend/tests/Feature/Hr/AttendanceTest.php`
- `frontend/src/pages/hr/AttendancePage.jsx`
- `frontend/src/pages/hr/MyAttendancePage.jsx`
- `frontend/src/pages/hr/attendance.css`

### REPLACE EXISTING

- `backend/routes/api.php`
- `backend/database/seeders/FoundationSeeder.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `frontend/src/components/navigation/navigationConfig.js`
- `frontend/src/router/index.jsx`

### DO NOT CHANGE

This package intentionally does not replace:

- Discount Rule model/service/controller/pages from Updates 38/40/41
- Customer Promo Filter Update 41 files
- `PackageServiceCreditService.php`
- `ServiceBalanceReadService.php`
- Checkout settlement files
- Commission Rule model/service/controller/page
- Personnel model/service/page

## Install

The ZIP may stay in Downloads.

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-PHASE-6B-ATTENDANCE-42.zip -d .

cd backend
php artisan migrate
php artisan db:seed --class=AttendancePermissionSeeder
```

Do **not** use `migrate:fresh`.

The permission seeder performs one important current-database cleanup:

- grants `my-attendance.VIEW` to the built-in Barber role
- removes Admin `attendance.*` permissions from the built-in Barber role

This keeps staff self-service separate from the Admin Attendance Dashboard.

## Tests

Run targeted Attendance tests first:

```bash
php artisan test --filter=AttendanceTest
```

Then HR/commission regression checks:

```bash
php artisan test --filter=PersonnelTest
php artisan test --filter=CommissionRuleTest
```

Then the full backend suite:

```bash
php artisan test
```

Frontend build:

```bash
cd ../frontend
npm run build
```

## Expected UI result

### Staff with `my-attendance.VIEW`

Sidebar shows **My Attendance**.

They can:

- select/take a proof photo
- Time In Now
- Time Out Now
- review their own attendance history

They cannot open the Admin Attendance Dashboard unless separately granted `attendance.VIEW`.

### Admin with `attendance.VIEW`

Sidebar shows **Attendance**.

### Admin with `attendance.EDIT`

Can approve or adjust attendance.

## Next HR phase

After Update 42 passes regression testing, continue to:

**Phase 6C — Operational Compensation / Daily Compensation foundation**

That phase should consume only `APPROVED` / `ADJUSTED` attendance as attendance-based compensation input and must snapshot Personnel hourly rate, OT entitlement, OT multiplier, compensation rule, and guaranteed daily pay before Payroll.
