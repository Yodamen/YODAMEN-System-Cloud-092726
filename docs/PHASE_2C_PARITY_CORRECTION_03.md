# Phase 2C Parity Correction 03

Date: 2026-09-12

This correction aligns the React Operations presentation more closely with the current Apps Script UI while preserving the Phase 2C backend behavior.

## Changes

- Bookings now mirrors the Apps Script page structure: page title + refresh, New Booking action, pill tabs, and a compact From / To / Search filter panel inside the list card.
- Active Bookings defaults the date range from today through the end of next month. Completed and Cancelled views clear the date range so historical records are not unintentionally hidden.
- Booking table column `Items` is now `Services` and shows the actual service names vertically rather than an item count.
- Queuing `Services` shows the actual service names vertically, with assigned staff beneath each service when available.
- Servicing is presented as a compact overview table with a `Services` column. The actual services are listed vertically. The Manage action opens the existing service-level workflow so staff reassignment, completion, cancellation, and Add Service remain available.
- Service names are vertically stacked and retain comma separation between entries for visual continuity with the legacy presentation.

## Database impact

None. This is a frontend-only correction.

## Validation required locally

Run:

- `npm run lint`
- `npm run build`

No migration or seeder is required.
