# YODAMEN Phase 5B — On Account Booking Payment Display 15

## Scope
Frontend-only transaction display update.

## On Account Payment column
- Do not show cumulative Total Paid in the On Account table.
- Show only payment activity whose Booking ID matches the Booking ID of that On Account row.
- Put the payment type directly under each displayed payment amount.
- Supported user-facing payment types are only:
  - Advance
  - Interim
  - Final Payment
- Do not combine payment type labels.
- If one real receipt contains both Advance and Interim allocations, show them as separate amount/type entries rather than `Advance + Interim`.
- Clicking a displayed amount still opens Payment Details.

## Other tabs
- Pending behavior is unchanged.
- Paid and Service Credit continue using their compact paid-amount presentation.

## Database
No migration required.

## Backend
No backend file change required for this display-only update.
