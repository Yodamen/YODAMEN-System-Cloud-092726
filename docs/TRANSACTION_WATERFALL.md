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


## Phase 4 implemented checkout waterfall

`Pending Sale -> manual Advance allocation -> optional CHECKOUT Payment -> Payment Applications -> financial finalization -> line settlement -> parent PAID/ON_ACCOUNT`

Rules now enforced:

- Every Advance allocation is persisted as a Payment Application against a current Sale line.
- Advance allocation equals the portion of received Advance required by the current Sale.
- Amount Collected Now creates one immutable `CHECKOUT` Payment only when greater than zero.
- Checkout allocation must equal Amount Collected Now.
- No allocation may exceed the target line balance.
- A remaining current-sale balance requires explicit `Allow Remaining Balance`.
- Finalization writes Payment/Application/Sale/Sale Item effects in one database transaction.
- Chargeable finalized lines become `PAID` or `ON_ACCOUNT`; Service Credit lines remain `SERVICE_CREDIT`.
- A mixed Sale can therefore appear in more than one finalized Transactions tab while the parent Sale remains one transaction.
- Later On Account receipt creates a `COLLECTION` Payment plus Payment Applications and never recreates commission.
- Duplicate checkout/collection submissions are protected by idempotency keys.
- Package-balance payment targets are reserved for the Package/Service Credit phase.

## Phase 4 revised On Account / Interim Payment waterfall

`Customer with finalized ON_ACCOUNT lines -> Receive Interim Payment -> one COLLECTION Payment receipt -> one or more Payment Applications -> affected Sale/line balances reconciled -> line may remain ON_ACCOUNT or move PAID`

Rules:

- The Transactions On Account tab is a settlement view and does not expose per-row collection buttons.
- Interim Payment begins at customer level and loads every open On Account Booking for that customer.
- Staff allocates the receipt to specific service lines grouped under Booking ID.
- One Payment may be allocated across multiple Booking obligations; the cash receipt is not duplicated.
- `payments.booking_id` may be null for a cross-booking receipt. Booking traceability lives in each Payment Application through its target Sale and Sale Item.
- The internal ledger type remains `COLLECTION` for parity with the approved accounting model; the UI label is **Interim Payment**.
- Interim Payment does not create a new commission. It only settles existing financially confirmed obligations.

## Phase 4 final Interim Payment rule

`New Booking / New Walk-In -> select customer -> detect active ON_ACCOUNT obligations -> collect payment -> choose INTERIM -> create new Booking -> create INTERIM Payment linked to the new Booking -> create Payment Applications against older On Account Sale Items -> reconcile older Sales/lines`

Rules:
- `ADVANCE` means money held for the new Booking's future Sale.
- `INTERIM` means money collected during the new Booking/Walk-In but applied to older On Account obligations.
- Every new Interim Payment carries the new Booking ID on the Payment header.
- Payment Applications retain the exact older Sale/Booking/service-line destinations being settled.
- Interim Payment is selected by default when the chosen customer has active On Account balances; staff must explicitly choose Advance if the money is intended for the new Booking.
- Interim Payment does not become an Advance on the new Booking's future Sale and is never auto-linked to that Sale by Sale Draft creation.
- The Transactions On Account tab does not accept standalone collection entry.


## Phase 4 split receipt rule

`New Booking/Walk-In -> one Payment receipt -> [Advance Reserve to new Booking] + [Interim Settlement to older On Account line(s)]`

Rules:
- One physical/electronic receipt creates one `payments` row.
- The new Booking ID is the collection context on the Payment header.
- Advance is represented by an `ADVANCE_RESERVE` Payment Application targeting the new Booking.
- Interim is represented by `ON_ACCOUNT_LINE` Payment Applications targeting older finalized Sale Items.
- Advance + Interim allocations must equal the receipt amount.
- When the new Booking later creates a Sale, only its unconsumed Advance Reserve is available at Checkout.
- Interim allocations are never auto-linked to the new Sale and can never be spent twice.

## Phase 5A package enrollment waterfall

`Multi-session service selected -> NEW_PACKAGE operational line -> pending Package Enrollment -> Queue/Visit lineage -> first package session completed -> PENDING_PACKAGE_SERVICE movement -> Sale Item type PACKAGE -> financial finalization -> enrollment-specific Service Credit issued -> Service Balances monitoring`

Rules:
- Every package purchase remains a separate Package Enrollment even when the same customer buys the same service again.
- A multi-session package is operationally quantity `1`; `number_of_sessions` defines the entitlement purchased.
- Completing the purchase visit before checkout records the first rendered package session without requiring payment.
- Financial finalization activates the full entitlement independently of package payment status.
- The first completed purchase session is recognized as used when the entitlement is issued, so a five-session package normally activates with four sessions remaining after the purchase visit.
- Package Collected and Balance are derived from valid received Payment Applications against the originating package Sale Item and, in Phase 5B, later applications directly targeting the Package Enrollment.
- Service Credit redemption of later sessions remains deferred to Phase 5B.
