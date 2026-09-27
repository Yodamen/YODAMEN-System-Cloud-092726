# Update 82A — Compensation Effective Date Control

## Purpose
Strengthen personnel compensation-term effective-date controls so new terms cannot begin on a date that already contains earned or pending compensation activity.

## Final rule
When operational compensation terms change, the earliest allowed effective date is the day after the latest protected compensation date for that personnel.

Protected sources are:
- latest Approved/Paid payroll period end;
- latest OPEN or IN_PAYROLL_DRAFT commission earning work date;
- latest OPEN or IN_PAYROLL_DRAFT daily compensation date with actual compensation activity, approved attendance, approved paid leave, commission, or non-zero projected compensation;
- latest OPEN or IN_PAYROLL_DRAFT payroll adjustment effective date.

The latest date among those sources becomes `blocked_through`. The next calendar day becomes `earliest_effective_date`.

Example:
- Last Approved/Paid payroll ends Sep 14.
- Pending commission exists on Sep 21.
- New compensation terms may start Sep 22 or any later date.

Future effective dates remain allowed.

## Frontend behavior
- The Personnel API now returns `compensation_effective_date_control` per personnel.
- When an admin changes an operational compensation field, the date selector receives the calculated minimum date.
- If the current date is earlier than the allowed date, it automatically advances to the earliest allowed date.
- The form displays the protected-through date and earliest allowed date.
- If compensation terms are restored to their original values, the existing effective date is restored.

## Backend enforcement
Frontend validation is advisory only. The backend independently recalculates the protected date before saving a changed compensation rule and rejects an invalid effective date with HTTP 422.

## Files changed
- `backend/app/Services/MasterData/PersonnelService.php`
- `backend/tests/Feature/MasterData/PersonnelTest.php`
- `frontend/src/pages/hr/PersonnelPage.jsx`

## Database
No migration required.
