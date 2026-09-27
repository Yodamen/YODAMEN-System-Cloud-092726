# YODAMEN Migration Status

**Current phase:** Phase 2C, Visit + Servicing Foundation  
**Blueprint:** Approved September 11, 2026  
**Target:** React + Vite / Laravel / MySQL (`yodamen_dev`)

## Phase 0

Status: **COMPLETE**

- Master Handoff reviewed.
- Current Apps Script source inspected.
- Current React/Laravel repository inspected.
- Current MySQL schema inspected.
- Repository-aware Migration Blueprint approved.

## Phase 1A

Status: **ACCEPTED / WORKING BASELINE**

Validated in the user environment:

- MySQL migrations completed.
- Foundation seeders completed.
- React production build passed.
- Username + Access Code login worked.
- Force Reset worked.
- New Access Code was accepted.
- Git checkpoint created:
  - `development`
  - `stable/approved`

Foundation includes Sanctum authentication, business/branch/users/roles/permissions, SQL audit logging, request correlation IDs, code sequencing, idempotency infrastructure, Asia/Manila clock, and authenticated React shell.

## Phase 1B

Status: **IMPLEMENTED IN SOURCE, USER-ENVIRONMENT VALIDATION REQUIRED**

Implemented:

- normalized `reference_groups` and `reference_values`
- safe system reference catalog based on current Apps Script categories
- stable system codes with editable labels
- Services master
- effective-dated Service Prices
- Single Session → exactly one session rule
- Service detail editing separate from price changes
- Personnel master
- Employee vs Partner separation
- Managing Partner position rule
- Employment Type separate from Personnel Type
- Hourly Rate
- OT Entitled + configurable OT Multiplier
- Guaranteed Daily Pay vs Commission profile
- No Operational Compensation option
- service eligibility and commission eligibility flags
- linked User uniqueness
- partner Profit Sharing eligibility/effective profile
- server-side permission enforcement
- mutation audit events
- React Reference Lists page
- React Services & Pricing page
- React Personnel page
- automated master-data tests
- existing Super Admin seeder hardened so reruns do not reset changed credentials

Validation gate before Phase 2:

1. Apply migration.
2. Run `MasterDataSeeder`.
3. Run backend tests.
4. Run frontend lint/build.
5. Validate Reference Lists create/edit/toggle.
6. Validate Service create/edit/effective price history.
7. Validate Employee Personnel.
8. Validate Partner/Managing Partner rules.
9. Confirm audit rows are written.
10. Commit approved Phase 1B checkpoint.

## Next phase after acceptance

**Phase 2: Operations Core**

Planned focus:

- Customers
- Booking
- Walk-In through Booking
- Queue
- Visit
- Visit Services
- Servicing
- completion/cancellation/rebooking event boundaries

Sales/Payments remain a later phase and will consume completed operational events rather than being embedded into Servicing.


## Phase 2A - Customers + Booking Foundation
Status: IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.

Includes customer master, reservation booking header/service/retail lines, rebooking history, booking cancellation, audit and React pages. Walk-In/Queue, advances, Service Credits and downstream Visit/Sales remain deliberately deferred.


## Phase 2A acceptance
Status: **ACCEPTED / WORKING BASELINE**

- 25 backend tests passed after duplicate-customer controls.
- Customer duplicate controls: normalized mobile OR normalized name + exact birthday.
- Customer and Booking browser validation completed.
- Approved Git checkpoint: `b8a1193`.

## Phase 2B - Queue + Walk-In + Check-In Foundation
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

Includes normalized Queue tables, reservation check-in, atomic Walk-In → Booking → Queue creation, legacy waiting-priority behavior, queue cancellation with preserved history, Reception Walk-In permissions, and React Queuing UI. Visit/Servicing and financial workflows remain deferred.


## Phase 2B acceptance
Status: **ACCEPTED / WORKING BASELINE**

- Queue/Walk-In browser flow validated.
- Approved Git checkpoint: `8b8d3c1`.
- `development` and `stable/approved` aligned before Phase 2C.

## Phase 2C - Visit + Servicing Foundation
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

Includes Queue → Start Servicing, one Visit per Queue entry, Visit service/retail snapshots, separate multi-staff service lines, active staff reassignment, add NEW_SERVICE during an active Visit, line-level completion/cancellation, mixed Visit outcomes, append-only service completion events, and the React Servicing workspace.

Financial boundaries remain enforced: service completion does not create Sales, Payments, Commission, Package settlement, or accounting entries.

## Phase 2C parity / UI correction 01
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

User review surfaced cross-phase parity/UI gaps before Phase 2C approval. Correction 01 adds the missing Bookings Completed tab, removes Queue/Servicing status tabs, makes Customer registration require only first/last name while retaining conditional duplicate controls, moves Personnel OT configuration into a dedicated section, integrates Service price scheduling/history into Edit Service, changes table actions to compact dropdowns, removes repeated business/date and module subheadlines, and applies a system-wide compact UI pass.

`stable/approved` must remain at Phase 2B (`8b8d3c1`) until this correction and Phase 2C are validated together.

## Phase 2C parity / UI correction 02
Status: **IMPLEMENTED PACKAGE, awaiting local UI acceptance.**

Queuing and Servicing were aligned to the current Apps Script live-workspace layout. Both pages now remove status/date controls and summary cards. Queuing shows the current business-date WAITING queue only, while Servicing shows current business-date IN_SERVICE visits only. A compact refresh control sits beside each page title. Bookings remains the only Operations page with Active / Completed / Cancelled tabs.

No backend schema or business-rule migration is required for this correction.


## 2026-09-12 - Phase 2C Parity Correction 03

- Refined Bookings tabs/filter layout to match the approved Apps Script visual structure.
- Replaced service/item counts with actual vertically stacked service names in Bookings and Queuing.
- Converted Servicing overview to a compact table with a Services column while retaining service-level management through a Manage modal.
- Frontend-only. No database migration required.

## Phase 3A - Sales Core
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

Includes:

- normalized `sales`, `sale_items`, and immutable `payments` ledger tables
- one Sale per completed Visit enforced by a unique database constraint
- one durable Sale Item per Visit service/retail source
- automatic Sale draft creation when a Visit becomes operationally `COMPLETED`
- received Advance Payments at Reservation Booking and Walk-In creation
- Advance Payment retention through rebooking
- automatic linking of Booking Advance Payments to the later Sale/Visit
- Pending parent Transactions page
- pending checkout detail showing Sale Items and received payments
- `FOR_CHECKOUT`, `PARTIALLY_PAID`, and `AWAITING_CONFIRM` pre-finalization states
- `yodamen:sales-sync` command for completed Visits created before Phase 3A installation
- payment-create permission for Manager and Reception
- database/audit safeguards against duplicate Sale creation

Phase boundary remains enforced:

- Phase 3A does **not** create Payment Applications.
- Checkout allocation/finalization is disabled until Phase 4.
- On Account/Paid/Service Credit line settlement is not activated yet.
- Commission is not created by service completion or advance receipt.
- Package/Service Credit financial logic remains Phase 5.

## Phase 3A parity / UI correction 01
Status: **IMPLEMENTED PACKAGE, awaiting local lint/build/tests/UI acceptance.**

Refines Advance Payment modal sizing, restores legacy-style user/access identity blocks, adds payment visibility across Booking/Queue/Servicing, reorganizes Queue references, adds inline New Customer shortcuts to Booking/Walk-In creation, and removes Branch from the Booking list display. Payment indicators are projections from the immutable Payment ledger rather than duplicated operational state.


## Phase 4 - Payment Allocation + Checkout Finalization
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

Includes:

- normalized `payment_applications` settlement ledger
- cached Sale/Sale Item applied/balance totals reconciled transactionally
- manual Advance allocation across current Sale lines
- immutable Checkout Payment receipt
- explicit `Allow Remaining Balance` control for On Account finalization
- atomic Sale financial finalization
- line-level `PAID`, `ON_ACCOUNT`, and `SERVICE_CREDIT` settlement classifications
- finalized Transactions tabs based on Sale Items rather than parent Sale status
- later On Account Collection payments and manual line allocation
- line movement from On Account to Paid when settled
- request idempotency for checkout finalization and collections
- audit events for checkout finalization and later collections

Boundary retained: Package Balance targets and Service Credit financial redirection remain for the Package/Service Credit phase. Commission posting remains deferred to the Commission phase, although financial finalization now provides the authoritative eligibility boundary.

## Phase 4 UX / On Account redesign 01
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

The Phase 4 settlement UX was simplified after user review. Checkout no longer asks for a duplicate Amount Collected Now field; the amount of new money is derived from service-line `Payment Now` allocations. Existing Advance is preallocated automatically and remains editable when a multi-line transaction needs manual distribution.

The row-level On Account `Collect` workflow is retired from the React UI and API routes. Later receipts are now handled through a customer-based **Interim Payment** workflow. Staff selects a customer, the system pulls all finalized On Account bookings/service-line balances for that customer, and one receipt can be allocated across one or more Booking obligations. The immutable Payment remains one cash receipt while Payment Applications preserve the exact Booking/Sale/line destinations.

`payments.booking_id` becomes nullable for a cross-booking Interim Payment header. Every allocation is still linked to a specific Sale and therefore its Booking/Walk-In schedule through `payment_applications.sale_id -> sales.booking_id`.

## Phase 4 final cleanup - Booking-linked Interim Payments

The standalone Transactions-page Interim Payment action is retired. A new customer receipt collected while creating a Booking or Walk-In is explicitly classified as either:

- `ADVANCE`: belongs to the newly created Booking and remains available for that Booking's future Sale.
- `INTERIM`: belongs to the newly created Booking as the collection context, but is immediately applied through Payment Applications to the same customer's older finalized `ON_ACCOUNT` service lines.

When a customer is selected in New Booking or New Walk-In, the UI checks for active On Account balances. If balances exist, Interim Payment is selected by default and the open Booking/service balances are shown for allocation. Staff may deliberately change the collection type to Advance when the money is intended for the new Booking instead.

The Transactions On Account tab remains a read-only settlement view. There is no standalone `Receive Interim Payment` action there.


## Phase 4 split Booking/Walk-In receipt refinement 04

A new Booking or Walk-In may now receive one cash receipt that is split between an **Advance Reserve** for the new Booking and **Interim Settlement** of older On Account balances for the same customer. The system stores one immutable Payment header and multiple purpose-specific Payment Applications. `payment_applications.sale_id` is nullable for Booking-level Advance Reserve rows and `target_booking_id` identifies the Booking receiving the reserve. Mixed receipts use `payments.payment_type = MIXED`. Checkout consumes only the Advance Reserve for its own Booking; older Interim allocations remain permanently tied to their original Sale Items.

## Phase 5A - Package / Service Credit Foundation
Status: **IMPLEMENTED PACKAGE, awaiting local migration/tests/UI acceptance.**

Phase 5A activates the enrollment-level package foundation required before Service Credit redemption is finalized. Multi-session master services now create `NEW_PACKAGE` operational lines automatically and one purchase-level Package Enrollment per transaction line. Package identity is propagated Booking -> Queue -> Visit -> Sale Item without cumulative customer/service stacking.

The first rendered session of a newly purchased package is recorded before financial activation. Financial finalization activates the package regardless of whether the package is fully paid or remains On Account, then issues one enrollment-specific Service Credit with the completed purchase session already recognized as used. Payment status and session entitlement remain independent.

Service Balances is now active as a read-only Operations module with one row per Package Enrollment. It exposes purchased, used and remaining sessions plus package-level Collected and Balance. Package financial detail derives money from received Payment Applications rather than cached package totals.

Phase 5A does **not** yet allow selecting an existing Service Credit in Booking/Walk-In or consuming a later credit session. That operational redemption and package-balance payment redirection belongs to Phase 5B. Advance / Interim / Checkout collection rules remain a working foundation and will be finalized together with Phase 5B settlement behavior.
