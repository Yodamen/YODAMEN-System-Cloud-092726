# YODAMEN Phase 3A Parity Correction 01

Date: 2026-09-12

This correction package applies on top of Phase 3A Sales Core. It does not introduce a database migration.

## UI / parity corrections

- Advance Payment entry is now compact: Amount is intentionally narrow, Payment Method remains readable, and Reference/Notes use balanced widths.
- Top-right application identity now mirrors the legacy Apps Script layout with user initial, display name, and access/role title.
- Sidebar lower-left now shows user name + access/role title, YODAMEN + Grooming Lounge, and Logout.
- Booking table no longer shows Branch under the Booking reference.
- Booking, Queue, and Servicing pages now expose a Payment column. Advance receipts are shown as a subtle amount under an Advance label; bookings without a received advance show Unpaid.
- Queue Customer subline now shows Walk-In or Reservation instead of queue/mobile references.
- Queue now uses a References column: Booking ID headline, Queue ID + check-in time subline, and scheduled reservation date/time where applicable. The standalone Check-In column is removed.
- New Booking and New Walk-In modals now include a + New Customer shortcut that creates a customer without leaving the active transaction modal and automatically selects the new customer.

## Backend projection correction

Queue and Servicing resources now expose `advance_payment_total`, derived from received ADVANCE Payment ledger rows linked to the Booking. No duplicated payment state is stored on Queue or Visit.

## Validation

- Modified PHP files pass `php -l` syntax validation.
- Modified React/JavaScript files pass TypeScript JSX parser validation.
- Relative frontend imports resolve.
- Added Sales Core test coverage for advance-payment projection across Booking → Queue → Servicing.
