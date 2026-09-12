# YODAMEN Parity Matrix

Legend: `DONE` target behavior implemented; `VALIDATE` implemented but pending user-environment/browser acceptance; `PLANNED` not yet migrated; `FUTURE` placeholder; `VERIFY-DATA` requires historical export reconciliation.

| Area | Legacy behavior/source | Laravel | React | MySQL | Automated test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Health | Existing React/Laravel smoke check | `/api/health` | API client | n/a | Health test | DONE |
| Username + Access Code login | `Auth.js` | Sanctum/session | Login page | User security fields | Authentication tests | DONE |
| Failed login lock | 5 attempts / 15 minutes | Server enforced | Errors surfaced | `failed_attempts`, `locked_until` | Lock test | DONE |
| Force Reset | `Auth.js` / modal | Access-code endpoint | Blocking reset modal | `force_reset` | Change-code test | DONE |
| Roles/action permissions | `REF_Roles`, `REF_Permissions` | Middleware/services | Permission-aware navigation | roles/permissions | Permission tests | DONE |
| Super Admin protection | `ROLE-SA` | protected | n/a | seeded role | tests | DONE |
| Business/Branch | setup/reference sheets | models/bootstrap | shell display | businesses/branches | seed/bootstrap | DONE |
| Audit | `Audit.js` | AuditService | n/a | `audit_logs` | audit coverage | DONE |
| Code/idempotency | IDs + locks | transactional services | n/a | sequence/idempotency tables | tests | DONE |
| Reference Lists | `REF_General`, `Admin.js` | ReferenceDataService/API | Reference Lists page | reference groups/values | reference tests | VALIDATE |
| Stable system reference codes | service/HR reference codes | server guarded | system code disabled in form | `is_system` | system-code test | VALIDATE |
| Services master | `DB_Services`, `Services.js` | ServiceCatalogService/API | Services page | `services` | service tests | VALIDATE |
| Effective-dated pricing | `DB_ServicePrices` | price-period rebuild | price modal/history | `service_prices` | price-period test | VALIDATE |
| Single vs Multi Session | `Service Count` | server enforced | form behavior | service sessions | service test | VALIDATE |
| Personnel | `DB_Employees`, `HRPayroll.js` | PersonnelService/API | Personnel page | `personnel` | personnel tests | VALIDATE |
| Employee vs Partner | V10 personnel rules | server enforced | form behavior | personnel ref FK | partner test | VALIDATE |
| Managing Partner | distinct position | server enforced | UI coercion | position ref | partner test | VALIDATE |
| Hourly/OT profile | HR V2/V10 | server validation | personnel form | personnel fields | OT test | VALIDATE |
| Profit Sharing profile | HR V10 | server validation | partner section | personnel fields | partner test | VALIDATE |
| Customers | `Customer.js` | DONE | DONE | DONE | Customer tests | DONE |
| Booking | `BookingVisits.js` | DONE | DONE | DONE | Booking tests | DONE |
| Queue | `BookingVisits.js` + `QueueVisitSetup.js` | VALIDATE | VALIDATE | VALIDATE | Queue tests | VALIDATE |
| Visit / Servicing | `BookingVisits.js`, `QueueVisitSetup.js`, Servicing UI | VALIDATE | VALIDATE | VALIDATE | ServicingTest | VALIDATE |
| Sales creation | `Sales.js` | VALIDATE | VALIDATE | VALIDATE | Sales core tests | VALIDATE |
| Payment ledger | `Sales.js` | VALIDATE | VALIDATE | VALIDATE | Sales core tests | VALIDATE |
| Payment allocation | `ServicingPaymentAllocation.js` | PLANNED | PLANNED | PLANNED | allocation | PLANNED |
| Package Enrollment | Service Credit sources | PLANNED | PLANNED | PLANNED | package | PLANNED |
| Service Credits | `ServiceCredit.js` | PLANNED | PLANNED | PLANNED | package/credit | PLANNED |
| Service Balances | `ServiceBalanceFinancial2026.js` | PLANNED | PLANNED | PLANNED | financial | PLANNED |
| Commission | bridge + multi-staff | PLANNED | PLANNED | PLANNED | multi-staff/reversal | PLANNED |
| Attendance | attendance source | PLANNED | PLANNED | PLANNED | privacy | PLANNED |
| Daily Compensation | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | compensation | PLANNED |
| Cash Advances | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | payroll | PLANNED |
| Payroll | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | lifecycle | PLANNED |
| Z-Reading | `ZReadings.js` + HR hook | PLANNED | PLANNED | PLANNED | close/payroll gate | PLANNED |
| Profit Sharing runs | `ProfitSharing.js` | PLANNED | PLANNED | PLANNED | lifecycle | PLANNED |
| Future Accounting | navigation/target rules | FUTURE | FUTURE | FUTURE | FUTURE | FUTURE |
| Future Inventory | navigation/target rules | FUTURE | FUTURE | FUTURE | FUTURE | FUTURE |
| Reports | navigation/target rules | FUTURE | FUTURE | read models | FUTURE | FUTURE |
| Historical Google Sheets data | live sheet rows | importer PLANNED | review report PLANNED | target tables | reconciliation | VERIFY-DATA |


## Phase 2A additions
| Legacy area | Laravel/MySQL | React | Tests | Status |
|---|---|---|---|---|
| Customer master CRUD/status | customers + CustomerService | CustomersPage | CustomerTest | Implemented, awaiting local acceptance |
| Booking reservation CRUD | bookings + BookingService | BookingsPage | BookingTest | Implemented, awaiting local acceptance |
| Booking service lines | booking_service_lines | Booking modal | BookingTest | Implemented for NEW_SERVICE only |
| Booking retail snapshot | booking_retail_lines | Booking modal | BookingTest coverage via booking workflow | Implemented |
| Rebooking history | booking_rebookings | Rebook modal | BookingTest | Implemented |
| Walk-In -> Booking -> Queue | Deferred to Phase 2B | Disabled | Pending | Correctly deferred |
| Advance Payment at Booking | Deferred to Payments ledger phase | Informational only | Pending | Correctly deferred |
| Service Credit claims | Deferred to Package/Service Credit phase | Not enabled | Pending | Correctly deferred |


## Phase 2B additions
| Legacy area | Laravel/MySQL | React | Tests | Status |
|---|---|---|---|---|
| Reservation check-in | QueueService + queue_entries | Booking Check In | QueueTest | Implemented, awaiting local acceptance |
| Walk-In common lifecycle | BKG + QUE in one transaction | New Walk-In modal | QueueTest | Implemented, no separate Walk-In transaction model |
| Queue service/staff snapshot | queue_service_lines | Queuing table | QueueTest | Implemented |
| Queue retail snapshot | queue_retail_lines | Queuing table | Queue workflow | Implemented |
| Queue priority | Derived legacy algorithm | Priority column | QueueTest | Implemented |
| Queue cancellation | append/history-preserving state | Cancel modal | QueueTest | Implemented |
| Reception Walk-In permission | syncWithoutDetaching Queue CREATE + Booking CREATE | permission-aware button | QueueTest | Implemented |
| Visit / Start Servicing | Deferred to Phase 2C | No active action | Pending | Correctly deferred |


## Phase 2C additions
| Legacy area | Laravel/MySQL | React | Tests | Status |
|---|---|---|---|---|
| Queue → Start Servicing | one Visit per Queue entry, transactional | Start Servicing action | ServicingTest | Implemented, awaiting local acceptance |
| Visit header | `visits` | Servicing workspace | ServicingTest | Implemented |
| Visit service lines | `visit_service_lines` with staff snapshots | line-level controls | ServicingTest | Implemented |
| Visit retail snapshot | `visit_retail_lines` | read-only servicing summary | ServicingTest | Implemented |
| Multi-service / multi-staff | independent service lines | independent staff selectors | ServicingTest | Implemented |
| Service completion event | append-only `service_completion_events` | event ID displayed after completion | ServicingTest | Implemented |
| Service cancellation | reason-required line history | Cancel Service modal | ServicingTest | Implemented |
| Mixed completed/cancelled outcome | Visit completes when ≥1 line completed and no active lines remain | preserved line states | ServicingTest | Implemented |
| All services cancelled | Visit/Booking/Queue cancelled | status projection | ServicingTest | Implemented |
| Add service during Visit | NEW_SERVICE only, Queue snapshot unchanged | Add Service modal | ServicingTest | Implemented |
| Service Credit completion | intentionally deferred | blocked | Pending | Correctly deferred |
| Financial finalization | intentionally separate | no checkout controls | Pending Sales phases | Correctly deferred |

## Phase 2C parity / UI correction 01
| Review item | Target behavior | Status |
|---|---|---|
| Booking history tabs | Active / Completed / Cancelled | Implemented, awaiting local acceptance |
| Queue navigation | Live WAITING queue only; no tabs/status/date filters; refresh beside title | Implemented, awaiting local acceptance |
| Servicing navigation | Live IN_SERVICE visits only; no tabs/status/date filters; refresh beside title | Implemented, awaiting local acceptance |
| Customer required fields | First Name + Last Name only | Implemented, awaiting local acceptance |
| Customer duplicate checks | Mobile OR Name + Birthday when supplied | Implemented, awaiting local acceptance |
| Customer registration date display | `Mmm. #, ####` | Implemented, awaiting local acceptance |
| Table actions | Compact dropdown | Implemented across current table modules |
| Global header | Remove repeated Business Name / Business Date | Implemented |
| Module title area | Module name only | Implemented across current active pages |
| Personnel OT layout | Full-width OT section adjacent to eligibility sections | Implemented |
| Service pricing | Price action inside Edit + historical price log | Implemented |
| UI density | Uniform compact typography/components | Implemented |

## Phase 2C Parity Correction 03

| Area | Apps Script behavior | React/Laravel status |
|---|---|---|
| Booking tabs | Active / Completed / Cancelled pill tabs | Matched |
| Booking filters | From / To / Search panel | Matched |
| Booking services display | Actual service names | Matched, vertically stacked |
| Queue services display | Actual service names | Matched, vertically stacked |
| Servicing services display | Services column with actual service names | Matched with compact overview + Manage workflow |

## Phase 3A Parity Correction 01

| Area | Target behavior | Status |
|---|---|---|
| Advance Payment input | Compact amount field + readable Payment Method layout | Implemented, awaiting local acceptance |
| Header identity | User initial + username + access/role title | Implemented |
| Sidebar footer | Username/role + YODAMEN/Grooming Lounge + Logout | Implemented |
| Booking Branch display | Branch removed from Booking list | Implemented |
| Booking Payment | Advance amount projection or Unpaid | Implemented |
| Queue Customer | Source shown as Walk-In / Reservation subline | Implemented |
| Queue References | Booking ID + Queue ID/check-in time; no standalone Check-In column | Implemented |
| Queue Payment | Advance amount projection or Unpaid | Implemented |
| Servicing Payment | Advance amount projection or Unpaid | Implemented |
| New Customer shortcut | Available inside New Booking and New Walk-In | Implemented |

## Phase 3A Parity Correction 02

| Area | Target behavior | Status |
|---|---|---|
| Customer filters | Search / Status / Birth Month / Last Visit / Lifetime Value / Reset | Implemented |
| Customer metrics | Completed Visits, Last Visit and actual collected Lifetime Value | Implemented from Visit + Sale/Payment ledgers |
| Global table spacing | Compact balanced columns; money/action columns shrink to content | Implemented |
| Confirmation dialogs | Branded modal instead of browser-native confirm | Implemented for Booking Check-In, Queue Start Servicing and Service Completion |
| Booking ID placement | Leading identifier column; queue retains sequence before it | Implemented |
| Booking Source | Proper capitalization | Implemented |
| Servicing columns | Standalone Check-In removed | Implemented |
| Transactions Pending | Services names replace item count | Implemented |
| Booking / Walk-In Advance | Duplicate payment Notes field removed | Implemented |
