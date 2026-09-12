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
