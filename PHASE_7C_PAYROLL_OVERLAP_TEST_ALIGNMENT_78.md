# YODAMEN Update 78 — Payroll Overlap Test Alignment

## Purpose
Align the Payroll overlap regression test with the current backend validation message.

## Change
Only `backend/tests/Feature/Hr/PayrollTest.php` is replaced.

The backend already correctly blocks overlapping active payroll runs with HTTP 422 and currently returns:

`An active payroll run already overlaps this payroll period for the selected branch scope.`

The older test still expected an earlier wording. This update changes the assertion only.

## Database
No migration required.

## Application behavior
No application logic, payroll calculations, UI, permissions, or database behavior changes.
