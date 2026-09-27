# Phase 5B — Booking Payment Type Rule 17

## Purpose
Refine the On Account Payment column so it shows only collections associated with that Booking and derives one concise payment type from the Booking's actual payment flow.

## Rules
- Booking balance is the authority for the payment-type label.
- If the Booking has no remaining balance, show `Final` regardless of whether the Booking used Advance, Interim, or Checkout collections.
- If a balance remains:
  - Advance + Checkout => `Advance + Checkout`
  - Interim + Checkout => `Interim + Checkout`
  - Checkout only => `Checkout`
  - Advance only => `Advance`
  - Interim only => `Interim`
- If Advance, Interim, and Checkout genuinely coexist while the Booking still has a balance, show `Advance + Interim + Checkout`.
- The displayed amount is limited to collections associated with that Booking, not cumulative lifetime payments on the sale item.
- Clicking the amount still opens Payment Details.

## Files
- `backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php`
- `frontend/src/pages/sales/TransactionsPage.jsx`

## Database migration
None.
