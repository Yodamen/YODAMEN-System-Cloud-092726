# YODAMEN Phase 5B — Payment Reference Continuity 10

## Purpose
Preserve Interim Payment visibility after Booking and use Booking ID as the universal operational reference in package payment history.

## Changes
- Servicing Manage now shows the Booking payment context, including Advance and Interim totals.
- Transactions Pending keeps the Booking's Interim amount visible without treating that amount as payment against the current sale when it was allocated to older balances.
- Paid / On Account transaction lines expose Interim amounts actually applied to each line.
- Finalized transaction lines also preserve the source Booking's Interim context when that receipt was used for older balances.
- Detailed sale payment history includes the Booking ID attached to each receipt when available.
- Package Payment Monitoring > Payment History now includes a Booking ID column.
- Package payment history prefers the receipt's source Booking ID and falls back to the applied/origin Booking when required.

## Financial Rule Preserved
Interim Payment remains an allocation to older On Account sale lines. Showing it on the newer Booking/Visit/Sale is contextual traceability only; it does not reduce the new sale balance unless that receipt also contains an Advance allocation for the new Booking.

## Migration
No database migration required.
