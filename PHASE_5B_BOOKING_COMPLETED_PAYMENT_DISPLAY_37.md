# Phase 5B - Booking Completed Payment Display 37

## Scope
Completed Bookings now show the payment type as the main Payment-column headline and the actual gross cash collected under that Booking as the subheadline.

## Rules
- Paid: finalized paid Booking without Service Credit claim.
- On Account: finalized Booking with outstanding balance.
- Service Credit: Booking with Service Credit claim and no received cash under that Booking.
- Paid Service Credit: Booking with Service Credit claim and at least one received cash payment under that Booking.
- Gross Collected is the sum of RECEIVED Payment receipts whose booking_id is that Booking. It is not the Booking gross/sale amount and it does not double-count payment applications.

## Files
- backend/app/Http/Controllers/Api/V1/Operations/BookingController.php
- backend/tests/Feature/Operations/BookingTest.php
- frontend/src/components/operations/PaymentIndicator.jsx
- frontend/src/pages/operations/BookingsPage.jsx

## Migration
None.
