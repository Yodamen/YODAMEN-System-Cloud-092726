# YODAMEN Phase 5B — Transaction Classification Repair 27

## Purpose
Restore the Transactions module to Booking-level integrity and correct finalized-tab classification.

## Rules implemented
- One finalized row per Booking ID.
- A normal fully settled Booking appears in Paid.
- A multi-session/package Booking with balance remains On Account; once the package balance reaches zero, it moves to Paid.
- A pure Service Credit redemption with no package payment remains in Service Credit.
- A Service Credit redemption with Advance or Checkout money applied to the underlying package is classified as On Account while that package still has balance.
- Once the underlying package is fully settled, that Service Credit payment Booking is classified as Paid.
- Payment amount on a finalized row is limited to payment allocations belonging to that Booking's transaction context; unrelated Interim allocations are not added to a Paid row.
- Service Credit package Advance/Checkout applications are shown as Advance or Checkout in payment history, not generic Interim.
- No Mixed tab is introduced.

## Database
No migration required.
