# YODAMEN Migration Status

**Current phase:** Phase 1B, Master Data Foundations  
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
