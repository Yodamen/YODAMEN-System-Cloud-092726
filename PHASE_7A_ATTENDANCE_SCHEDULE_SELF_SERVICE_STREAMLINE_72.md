# YODAMEN Phase 7A — Attendance / Schedule / Self-Service Streamline — Update 72

## Scope
Frontend-only UX refinement built on the current React/Laravel system through Update 71.

## REPLACE EXISTING
- `frontend/src/pages/admin/BusinessSetupPage.jsx`
- `frontend/src/pages/hr/MyAttendancePage.jsx`
- `frontend/src/pages/hr/MyLeavePage.jsx`
- `frontend/src/pages/hr/WorkScheduleModal.jsx`
- `frontend/src/pages/hr/attendance.css`
- `frontend/src/pages/hr/leave.css`

## ADD NEW
- `frontend/src/pages/admin/business-setup.css`

## DO NOT CHANGE
- Attendance backend timestamps / proof validation
- Leave approval rules
- Work-schedule API/schema
- Payroll calculations
- Commission logic

## Changes

### Business Setup
- Branch modal Address now uses the full modal width.
- Cancel / Save Branch buttons sit below Address and use normal compact button height.
- Add Branch is pinned to the upper-right of the Branches card heading.

### My Attendance
- Removes employee name/code from the attendance action card.
- Removes the `Server Time` label.
- Current server date/time renders as `Sep 19, 2026 (Saturday), 1:47 PM.`.
- Time In, Time Out, Worked, proof upload, and Time In/Out action now live in one compact card.
- From / To (or Month) and Apply are grouped together.
- List / Calendar sits at the far right of the filter card.
- Removes the `View` label.
- List / Calendar control height matches the date inputs.

### Leave / Day Off
- Leave balances use a compact strip instead of large cards.
- Leave filing keeps Leave Type, Duration, From, To, and Reason in one compact form.
- Day Off filing is simplified to one Date + Reason. It posts a one-day, full-day request using the existing API.
- Request History removes Reason from the table and provides a branded View modal for complete details.

### Personnel → Work Schedule
- Weekly schedule converted to a compact row/table layout.
- Workday/Day Off, Expected Hours, and optional Shift are easier to scan.
- Template action shortened to `Mon–Sat · 8h`.
- Manual Date Overrides renamed `Admin Schedule Exceptions` to distinguish them from employee-filed HR requests.
- Approved HR-generated schedule effects are clearly read-only and separated from admin exceptions.

## Installation
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-ATTENDANCE-SCHEDULE-SELF-SERVICE-STREAMLINE-72.zip -d .
```

No database migration is required.

Build frontend:

```bash
cd frontend
npm run build
```

Optional lint check:

```bash
npm run lint
```

Existing lint issues elsewhere in the project may still be reported independently of this update.

## Manual regression checks
1. Business Setup → Add Branch: Address spans full width and action buttons are below it.
2. Business Setup → Branches: Add Branch is upper-right of the card header.
3. My Attendance: current date includes weekday and no employee/server-time labels appear.
4. My Attendance: upload proof + Time In/Time Out still works.
5. My Attendance filters: Apply is next to date fields; List/Calendar is right-aligned.
6. My Attendance → Leave: submit a leave request and open it using View.
7. My Attendance → Day Off: submit one date; verify request history.
8. Personnel → Work Schedule: save weekly schedule and an admin exception.
9. Calendar view: Present/Absent/Day Off/Partial/Leave rendering remains unchanged.
