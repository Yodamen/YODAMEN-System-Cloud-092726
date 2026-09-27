# YODAMEN Phase 5B - Booking Advance / Manual Checkout Allocation 28

## Scope
Frontend-only correction based on the approved Phase 5B transaction rules.

## Changes

### Booking payment allocation
- If the new Booking contains only Service Credit claim lines and no new service or retail purchase, the payment panel no longer shows the `New Booking` Advance allocation card.
- In that scenario, the receipt can only be allocated to `Older On Account Bookings` as Interim payment.
- If a new service or retail purchase is present, the New Booking Advance allocation remains available.
- The Booking save payload forces `advance_amount = 0` when there is no current Booking charge target, preventing a hidden/stale Advance value from being submitted.

### Checkout advance allocation
- Existing Advance is never auto-filled into the Collected column, even when only one eligible line exists.
- The user must manually type/distribute the existing Advance in the Collected column.
- Finalize Checkout remains blocked until the manually entered Collected allocations exactly equal the Advance amount required for the Booking.
- This applies to regular service/package lines and eligible Service Credit package-balance allocations.

## Files
- frontend/src/components/operations/InitialPaymentCollection.jsx
- frontend/src/pages/operations/BookingsPage.jsx
- frontend/src/components/sales/CheckoutModal.jsx

## Database
No migration required.

## Backend
No backend files changed. Existing backend validation already requires the Advance allocations submitted at Checkout to equal the required Advance amount.
