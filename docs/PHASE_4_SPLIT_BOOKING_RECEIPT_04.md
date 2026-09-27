# Phase 4 Split Booking Receipt 04

## Rule
A payment collected while creating a new Booking or Walk-In is one real cash receipt, even when the customer wants to use that receipt for more than one purpose.

The receipt can be split into:

- **Advance Reserve**: held for the newly created Booking/Walk-In and available to its future Sale at Checkout.
- **Interim Settlement**: immediately applied to one or more older finalized On Account service lines for the same customer.

The system must never create two Payment headers merely because one cash receipt has both purposes.

## Ledger model

`payments` remains the immutable cash-receipt header. The Payment header carries the new Booking ID as the collection context.

`payment_applications` carries allocation purpose and destination:

- `ADVANCE_RESERVE` -> `target_booking_id` = new Booking
- `ON_ACCOUNT_LINE` -> `sale_id` / `target_sale_item_id` = older finalized On Account obligation
- `ADVANCE_LINE` -> future consumption of the Advance Reserve against the new Booking's Sale
- `CHECKOUT_LINE` -> current Checkout payment allocation

A mixed receipt uses `payments.payment_type = MIXED`. Pure receipts may remain `ADVANCE` or `INTERIM`.

## Booking/Walk-In UX

When a customer is selected, the system loads active On Account balances. If none exist, the full receipt is reserved as Advance for the new Booking/Walk-In.

When active On Account balances exist, staff enters one Amount Received, then allocates it between:

1. Advance for the current Booking/Walk-In.
2. Interim amounts applied to older On Account service lines.

Saving is rejected unless total allocations equal the receipt amount exactly.

## Checkout behavior

Only the Advance Reserve belonging to the current Booking becomes available to its future Sale. Interim allocations applied to older Sale Items can never be reused by the new Sale.
