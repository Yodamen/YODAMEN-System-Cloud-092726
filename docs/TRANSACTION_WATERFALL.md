# YODAMEN Transaction Waterfall

This document fixes the event boundaries that later migration phases must preserve.

## Standard service

`BookingCreated -> Queue/Check-In -> VisitStarted -> VisitServiceCompleted -> SaleDraftCreated -> PaymentReceived/Applied -> SaleFinalized -> CommissionEligible`

Operational completion can also produce customer-history and future inventory-consumption effects. Commission waits for Sale finalization.


## Phase 2C operational completion boundary

`Queue WAITING -> Start Servicing -> Visit IN_SERVICE -> Visit Service COMPLETED/CANCELLED -> Visit operational outcome`

Rules:

- Start Servicing creates exactly one Visit from the Queue entry.
- Initial Queue service lines are snapshotted into Visit service lines.
- Each Visit service has an independent staff assignment and status.
- Each completed service creates exactly one append-only `service_completion_events` record.
- If another active service remains, the Visit stays `IN_SERVICE`.
- If all lines are cancelled, Visit + Booking + Queue become `CANCELLED`.
- If at least one line is completed and no active lines remain, Visit + Booking + Queue become `COMPLETED`.
- The completion event is an operational source for later Customer History, Inventory Consumption, Sale drafting, and Commission eligibility checks.
- No Payment, Sale finalization, revenue, AR, or Commission is created by Phase 2C service completion itself.

## Advance payment

`BookingCreated -> AdvancePaymentReceived`

The Advance is a Payment linked to the Booking. It does not finalize a Sale or create commission. When the Visit later creates a Sale, checkout allocates the existing Advance to valid current Sale lines and/or an originating package balance where allowed.

## Checkout

1. Lock the mutable Sale/targets.
2. Load existing valid payments and applications.
3. Validate manual allocation totals and target capacities.
4. Create checkout Payment when new money is collected.
5. Create Payment Applications.
6. Reconcile current Sale and Package/source balances.
7. Finalize Sale and Sale Items.
8. Activate/reconcile packages as applicable.
9. Reconcile commission eligibility exactly once.
10. Commit core financial changes atomically.
11. Emit downstream after-commit events/read-model refreshes.

## On Account

A completed chargeable line with a remaining balance after Sale finalization is `ON_ACCOUNT`. Commission can already be created because the Sale is financially confirmed. Later collection creates a `COLLECTION` Payment and Payment Applications against the remaining obligation. Collection does not create a new commission.

## Package purchase

Package Sale finalization creates/activates one Package Enrollment. Package purchase service commission is zero. Financial settlement is attached to the package/source Sale obligation. Entitlement/session usage is tracked separately from package payment settlement.

## Service Credit redemption

A rendered Service Credit line has zero current Sale charge and remains `SERVICE_CREDIT`. It consumes entitlement and can earn rendered-session commission after financial confirmation. It does not create new service revenue.

If real money is collected during redemption to settle the old package balance, the Payment Application targets the originating Package Enrollment/source Sale. Current redemption classification remains `SERVICE_CREDIT`.

## Cancellation / reversal

Pending operational state may be cancelled under workflow rules. Finalized historical effects are reversed or adjusted rather than silently deleted. Open downstream records can be recalculated; Approved/Paid Payroll and Profit Sharing remain frozen and receive future adjustments when necessary.

## Payroll

`AttendanceApproved -> DailyCompensation -> ConfirmedCommission/Adjustments -> PayrollRun DRAFT -> APPROVED -> PAID`

Required work dates must satisfy the approved Z-Reading/EOD close gate before payroll finalization. A source earning/compensation row can be used once in an active payroll.

## Profit Sharing

`Eligible Partner Configuration -> ProfitSharing DRAFT -> APPROVED -> PAID`

Partner percentages are snapshotted at the applicable Period End. Approved/Paid history remains immutable.

## Phase 3A Sales Core boundary

`Booking/Walk-In -> optional ADVANCE Payment -> Queue -> Visit -> completed service lines -> Visit COMPLETED -> Sale Draft + Sale Items -> Pending Transactions`

Rules implemented in Phase 3A:

- Advance cash is stored only in `payments`, never as a Booking money field.
- Rebooking keeps the same Booking and the same Advance Payment.
- Advance receipt does not finalize a Sale and does not create commission.
- Visit completion creates exactly one parent Sale using SQL uniqueness on `sales.visit_id`.
- Each completed Visit service creates one durable Sale Item source; cancelled service lines are excluded.
- Visit retail snapshots become Sale Items.
- Existing received Advance Payments are linked to the Sale when the Sale is created.
- `FOR_CHECKOUT`: no received payment yet.
- `PARTIALLY_PAID`: Advance exists but current chargeable gross remains outstanding.
- `AWAITING_CONFIRM`: received Advance covers the current gross, but explicit financial confirmation is still required.
- Pending Transactions remains one row per parent Sale/Booking.
- No Payment Application or line settlement is created until Phase 4.
