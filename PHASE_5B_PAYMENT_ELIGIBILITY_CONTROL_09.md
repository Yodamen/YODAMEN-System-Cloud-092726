# YODAMEN Phase 5B - Payment Eligibility Control 09

## Business rule
- Single-session services must be fully settled before Checkout can be finalized.
- Product/Retail Purchase lines must also be fully settled before Checkout can be finalized.
- Multi-session package lines may retain an On Account balance and receive later payments.
- Service Credit redemption remains non-cash and has no new service charge.
- Earlier Advance Payments may still be applied to a single-session service; the remaining amount must be cleared at Checkout.

## Backend control
`CheckoutSettlementService` now validates the projected balance per Sale Item before any checkout payment is created.
Only Sale Items with `item_type = PACKAGE` are installment eligible.

## Frontend control
Checkout identifies each chargeable line as either:
- Full payment required
- Multi-session · balance may remain

The Finalize Checkout button remains disabled while any non-installment-eligible line has a balance.
The On Account checkbox is shown only when all required-full-payment lines are settled and a multi-session package still has a balance.

## Tests
Checkout tests now use multi-session package lines for legitimate On Account scenarios and include a hard-control test that rejects an unpaid single-session service even when `allow_remaining_balance` is submitted as true.

## Migration
No database migration required.
