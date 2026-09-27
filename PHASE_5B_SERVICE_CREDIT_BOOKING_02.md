# YODAMEN Phase 5B - Service Credit Booking Integration 02

## Scope
This update connects active Service Credits to Booking and Walk-In operations.

## User-facing behavior
- Selecting a customer automatically checks active Service Credits.
- The form shows each Service Credit's remaining, reserved, and currently available sessions.
- Service dropdowns contain a `Use Service Credit` group above normal new services/packages.
- Selecting a Service Credit locks quantity to one session and marks the line as no-new-charge Service Credit usage.
- Multiple active bookings cannot reserve more Service Credit sessions than are available.
- Existing reservations are considered when displaying `available_sessions`.
- Editing the same Booking excludes that Booking's own reservation from the availability check.
- Rebooking validates that the Service Credit is still valid for the new date.

## Operational behavior
- Booking/Walk-In lines using Service Credit carry `source_type=SERVICE_CREDIT`, `service_credit_id`, and the originating `package_enrollment_id`.
- Queue and Visit lineage retain those identifiers.
- When the service is completed, one Service Credit session is consumed.
- Service Credit Usage and append-only credit ledger records are created.
- The resulting Sale Item is `SERVICE_CREDIT_USE` with zero line charge.
- Original package-purchase lineage is not overwritten by later redemption visits.

## Database
No new migration is required. Phase 5A already added the required Service Credit lineage columns and tables.

## Validation
- PHP syntax checked across the backend.
- JSX parse checked for changed React files.
- Four feature tests were added for detection, reservation, over-reservation prevention, and consumption.
