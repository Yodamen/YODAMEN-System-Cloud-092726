# YODAMEN Phase 5B — Package ID + Running Balance 35

## Scope
Frontend/backend transaction display correction only. No migration.

## Changes
1. Multi-session transaction rows now display the unique Package Enrollment ID (`PKE-...`) instead of the Sale ID (`SAL-...`).
2. Pending and finalized transaction Service columns use `package_code` from `PackageEnrollment.code`.
3. On Account package-related rows now calculate the displayed balance as the running package balance immediately after that row's Booking was financially finalized, rather than repeating the package's latest/current balance across historical Booking rows.
4. Paid package-related rows display zero balance.
5. Previous Transaction table/UI hierarchy from Update 34 is preserved.

## Running-balance rule
For an On Account package-related transaction row:

`Package Original Amount - all active received payment applications to the package origin line created through that Booking's financial finalization time`

This keeps each Booking row historically meaningful even after later Bookings make additional payments against the same package.

## Files
- `backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php`
- `frontend/src/pages/sales/TransactionsPage.jsx`

## Migration
None.
