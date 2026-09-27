# YODAMEN Phase 5B - Checkout Layout Simplification 31

Frontend-only UI cleanup.

## Changes
- Checkout service rows now keep only concise identifiers under the service title:
  - Assigned Staff
  - Transaction Type
  - Source Booking ID when the line is a Service Credit tied to an original package sale
- Removed verbose Service Credit helper sentence about applying Interim payment and open package balance.
- Removed `Booking Interim` helper text under Collected.
- Removed `Interim` helper text under Checkout Payment.
- Removed package-balance helper text under Balance After.
- Removed helper sentence under the Checkout Payment total, including `No Checkout Payment is currently entered.`
- No financial allocation, settlement, classification, or backend behavior changed.

## File replaced
- `frontend/src/components/sales/CheckoutModal.jsx`

## Migration
- None.
