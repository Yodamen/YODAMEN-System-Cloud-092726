# YODAMEN Business Rules

**Source of truth:** Master Migration / Replication Handoff Protocol approved for the React + Laravel + MySQL migration.

## Core lifecycle

- Booking -> Queue -> Visit -> Servicing -> Completed / Cancelled.
- Walk-ins use the same Booking/Queue/Visit lifecycle rather than a separate transaction model.
- Internal SQL IDs are relational keys. Human-readable legacy-style codes remain immutable business identifiers.

## Operational, cash, and financial events are separate

1. A Visit Service becoming `COMPLETED` is an operational event.
2. A Payment records cash actually received.
3. A Payment Application records where that cash was applied.
4. A Sale finalization is the financial confirmation event.
5. Commission eligibility requires a completed service plus a financially confirmed Sale.

Service completion alone does not create commission.

## Transactions

- Pending Transactions display one parent row per Sale/Booking.
- Finalized tabs display service lines classified as `PAID`, `ON_ACCOUNT`, or `SERVICE_CREDIT`.
- A mixed Sale remains one parent transaction even when its lines appear in several finalized tabs.
- Service Credit lines remain `SERVICE_CREDIT` even when real money is collected during the same booking.

## Payments and applications

- Payments are immutable cash-ledger records except through explicit void/refund/correction workflows.
- Payment Applications identify the obligation settled by each payment.
- Advance, Checkout, and Collection payments can be allocated across eligible targets.
- Service Credit interim cash directed toward an old package balance applies to the originating Package Enrollment/source Sale rather than the zero-charge redemption line.
- Financial submission endpoints require durable idempotency protection.

## Packages and Service Credits

- A package purchase is a financial purchase and entitlement container.
- Package purchase service commission is zero.
- Rendered package sessions can earn commission after completed service + financial confirmation.
- Service Balances are tracked per Package Enrollment. Separate purchases of the same package remain separate enrollments.
- Package payment status is independent of session usage.

## Commission

- Commission is service-line based and idempotent.
- Split commission is not part of the currently approved design.
- On Account lines can generate commission after financial finalization; collection completion is not required.
- Invalid open commissions are reversed, not deleted.
- Approved/Paid payroll is never rewritten because of a later commission reversal; use a future adjustment.

## Attendance, compensation, and payroll

- Self-service Time In/Out is only for the logged-in linked Personnel record.
- Attendance proof is mandatory and privately stored.
- Attendance timestamps are server-generated.
- Approved/present attendance feeds compensation.
- Hourly Rate is the base compensation source. The deprecated Monthly Divisor behavior is not migrated.
- OT multiplier is configured per employee and is never hard-coded.
- `GUARANTEED_VS_COMMISSION = max(Guaranteed Daily Pay, confirmed Daily Commission)`.
- Payroll lifecycle is `DRAFT -> APPROVED -> PAID`.
- Approved/Paid payroll snapshots are frozen.
- Required payroll dates must have the required Z-Reading/EOD close marker.

## Profit Sharing

- Profit Sharing is separate from Payroll.
- Lifecycle is `DRAFT -> APPROVED -> PAID`.
- Partner percentage is snapshotted according to the Period End effective date.
- Approved/Paid runs remain frozen.

## Personnel and users

- User account and Personnel are separate entities.
- Personnel Type, Employment Type, and Position are separate concepts.
- Managing Partner is a Position, not a Personnel Type.
- Personal pages must be scoped to the logged-in linked Personnel record.

## Permissions

- Permissions are module + action based.
- Laravel is the authoritative permission layer. React visibility is UX only.
- `ROLE-SA` is protected and cannot have its core permissions stripped through normal permission management.
- Permission changes are audited.

## Audit and correction

- Important mutations create SQL audit records with actor, action, entity, timestamp, request/correlation ID, and contextual metadata.
- Reason-required corrections remain explicit.
- Finalized financial, commission, payroll, profit-sharing, and inventory history is preserved through reversal/adjustment records rather than destructive rewrites.

## Concurrency and integrity

- Database transactions, unique constraints, row locking, and idempotency keys replace Apps Script ScriptLock/browser deduplication as correctness controls.
- One Visit can produce only one active Sale.
- One operational source line can produce only one matching Sale Item identity.
- Duplicate financial submissions must replay the original result instead of creating duplicate money movement.
