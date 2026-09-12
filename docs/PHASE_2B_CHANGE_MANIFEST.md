# Phase 2B Change Manifest

Phase 2B activates Queuing, reservation check-in, and Walk-In creation while keeping Visit/Servicing and all financial logic outside this package.

## Added
- `queue_entries`, `queue_service_lines`, `queue_retail_lines`
- Queue Eloquent models
- `QueueService`
- Queue API controller and validation requests
- Reservation check-in endpoint
- Atomic Walk-In → Booking → Queue endpoint
- Waiting/cancelled Queue list and legacy priority ordering
- Queue cancellation with reason and preserved Booking history
- Reception Walk-In permission seeder (`queue.CREATE` + `bookings.CREATE`)
- React Queuing page
- Booking Check In action
- Phase 2B automated tests

## Deliberately deferred
- Advance Payment / Payments ledger
- Service Credit claims
- Visit creation / Start Servicing
- Visit service completion
- Sales / Checkout / Commission / Inventory consumption
