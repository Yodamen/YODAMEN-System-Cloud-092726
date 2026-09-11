# YODAMEN Management System Migration Blueprint

**Blueprint date:** September 11, 2026  
**Revision:** Final repository-aware blueprint (Revision 2)  
**Target stack:** ReactJS + Vite, Laravel, MySQL  
**Development database:** `yodamen_dev`  
**Version-control intent:** `stable/approved` for approved checkpoints, active development branch for ongoing work  
**Implementation status:** Blueprint only. No production code has been generated or modified.

## 1. Executive conclusion

The YODAMEN migration is a behavioral replication and data-model normalization project. The Apps Script application contains mature operational and financial rules across Booking, Queue, Visit/Servicing, Sales, Payment Allocation, Packages/Service Credits, Commission, Attendance, Payroll and Profit Sharing. The React/Laravel/MySQL application should preserve those approved outcomes while replacing spreadsheet-row mechanics, global runtime overrides, compatibility bridges and ScriptLock race protection with relational constraints, explicit Laravel domain actions, database transactions and modular React components.

All supplied migration sources have now been inspected: the Master Migration Handoff, the current Apps Script source bundle, the `yodamen_dev` schema-only dump, and the actual React/Laravel target repository ZIP. The target repository is a clean application skeleton rather than a partially migrated YODAMEN implementation. That is favorable because there is no competing domain architecture to unwind before migration begins.

The verified target baseline is:

- Laravel application with PHP constraint `^8.3`; `composer.lock` currently pins `laravel/framework` **v13.31.0**, `laravel/sanctum` **v4.3.3**, `laravel/tinker` **v3.0.2**, Laravel Boost **v2.8.0**, and Pest **v5.1.4**.
- Separate React/Vite frontend with React **19.2.8**, React DOM **19.2.8**, React Router DOM **7.18.3**, Axios **1.20.0**, and Vite **8.2.2**.
- Laravel currently exposes only `/api/health`; there are no YODAMEN domain controllers, services, requests, policies or business routes.
- The only application model is the default-style `User` model. Authentication packages/configuration exist, but no YODAMEN login/logout/me flow has been implemented.
- React currently renders only a Laravel health-connection screen. Router, layouts, sidebar, module pages and reusable API/domain components are not implemented.
- The supplied MySQL dump contains Laravel framework/auth infrastructure only. No YODAMEN domain tables exist yet.
- The repository ZIP does not contain `.git` metadata, so the actual current branch names/state cannot be verified from the archive. This does not block design or implementation, but Git branch checks must occur in the user's working clone before the first coding checkpoint.

The most important architecture boundary remains mandatory:

**Operational completion -> cash receipt/application -> financial finalization are separate concepts.**

A completed Visit Service may update operational history, consume package entitlement and later drive inventory consumption. Commission becomes eligible only when the rendered service is completed and the related Sale is financially confirmed. A Payment records money actually received. A Payment Application records which current Sale line or originating Package Balance that money settled. These concepts must remain separate in MySQL and Laravel.

The migration is now at **Phase 0 completion / Blueprint approval gate**. Source and repository inspection are complete. No production module code should be written until this Revision 2 blueprint is approved. After approval, the smallest safe implementation package is **Phase 1 Foundation and Security**.
## 2. Source audit status

| Source | Inspected? | Verified finding |
| --- | --- | --- |
| Master Migration / Replication Handoff | Yes, full document | Authoritative business-rule and migration-precedence specification. |
| `YODAMEN_CURRENT_APPSCRIPT_SOURCE.zip` | Yes, extracted and statically inspected | 65 real Apps Script/UI/config files after excluding macOS metadata. Core workflows plus compatibility, repair and diagnostic layers are present. |
| `yodamen_database_schema.sql` | Yes, full schema | `yodamen_dev` contains Laravel framework/auth infrastructure only; no YODAMEN business tables. |
| `YODAMEN-REACT-LARAVEL-SOURCE.zip` | Yes, extracted and inspected | Clean Laravel + separate React/Vite skeleton. No YODAMEN domain implementation exists yet. |
| Historical Google Sheets row data | No, not supplied | Source code reveals sheet schemas/relationships, but actual row counts, dirty-data extent, orphan records and financial reconciliation remain unavailable until controlled export. |

### 2.1 Verified Laravel baseline

| Area | Current repository state | Migration implication |
| --- | --- | --- |
| Framework | `laravel/framework` v13.31.0 locked; PHP `^8.3` constraint | Use Laravel 13 APIs and existing conventions. |
| Auth package | Sanctum v4.3.3 installed/configured | Good first-party SPA baseline, but YODAMEN auth endpoints and stateful frontend configuration still need implementation. |
| API routes | Only `GET /api/health` | `/api/v1` domain API can be introduced without route conflict. |
| Models | Only `App\Models\User` | YODAMEN domain models are still absent. |
| Controllers/services/policies/requests | No YODAMEN implementation | Domain architecture can be created cleanly after approval. |
| Database migrations | Users, cache, jobs, personal access tokens only | All YODAMEN schema migrations remain to be created. |
| Testing | Pest installed; template Example tests only | Parity suite must be built from the handoff waterfall cases. |
| Queue | Database queue configured by default | Suitable for non-critical async work after required tables/workers are configured. |
| Sessions | Database sessions configured | Suitable for Sanctum/session SPA approach. |
| Files | Local private disk points to `storage/app/private` | Appropriate baseline for attendance proof privacy; deployment disk can later change through Laravel storage abstraction. |
| App timezone | `config/app.php` currently hard-codes `UTC` | YODAMEN business-date behavior requires explicit Asia/Manila handling before operational date logic is implemented. |
| `.env.example` database | Defaults to SQLite | Must be changed to the YODAMEN MySQL development convention before foundation setup. |
| Backend Blade/Vite | Default Laravel frontend assets also exist | Keep separate from the YODAMEN React app unless a deliberate deployment consolidation is later approved. |

### 2.2 Verified React baseline

| Area | Current repository state | Migration implication |
| --- | --- | --- |
| React | 19.2.8 | Keep the existing JavaScript React baseline unless a separate TypeScript migration is approved. |
| Vite | 8.2.2 | Existing build system is suitable. |
| Router | `react-router-dom` 7.18.3 installed but unused | Build module routing on the installed router rather than adding another routing package. |
| HTTP client | Axios 1.20.0 installed | Reuse Axios through one centralized API client. |
| App component | Health-check request to `${VITE_API_URL}/health` only | Replace progressively with authenticated shell/router after Phase 1 approval. |
| Layout/navigation | None | YODAMEN sidebar/header/layout must be created from parity requirements. |
| State/query library | No TanStack Query/Redux/Zustand installed | Do not add a state library automatically. Start with React context/hooks and Axios; add a query library only when justified and approved. |
| Styling | Template CSS with purple Vite styling | Replace with YODAMEN dark/gold design system during the approved foundation/UI shell work. |
| Frontend env contract | `VITE_API_URL` is already expected by `App.jsx` | Preserve an environment-driven API base URL. |

### 2.3 Repository-specific configuration issues to resolve in Phase 1

1. `.env.example` advertises SQLite while the approved development database is MySQL `yodamen_dev`.
2. `APP_NAME` still defaults to Laravel and frontend `<title>` is still `frontend`.
3. Laravel application timezone is currently UTC, while approved YODAMEN business-time behavior is Asia/Manila. Database timestamp strategy can still preserve UTC instants, but business-date rules must be explicit and consistent.
4. Sanctum's default stateful-domain string does not explicitly include Vite's normal `localhost:5173` / `127.0.0.1:5173` origins. SPA cookie authentication must account for the actual frontend origin.
5. No CORS/auth bootstrap flow has yet been implemented for the separate React SPA.
6. `DatabaseSeeder` creates a generic test user. It must not become the production YODAMEN Super Admin seeding strategy.
7. The ZIP contains generated Laravel `public/build` assets. These are build outputs, not source-of-truth frontend architecture.
8. `.git` metadata is absent from the ZIP. Branch verification must happen in the working repository before the first implementation commit.
## 3. Governing migration principles

1. The handoff's explicit approved business rules have the highest precedence when legacy code contains compatibility layers, accidental bugs or incomplete experiments.
2. Current supplied source files determine exact legacy fields, labels, statuses, edge behavior and migration evidence.
3. SQL normalization should preserve approved workflow results without carrying forward sheet-row mechanics.
4. Financial and permission rules receive server-side validation plus database constraints where practical.
5. Finalized financial, commission, payroll and profit-sharing history remains traceable. Corrections use reversals or adjustments.
6. Critical multi-table financial writes run inside one database transaction. Unique indexes and idempotency keys handle retries/concurrency.
7. React presents state and collects user intent. Laravel and MySQL remain authoritative for status transitions, calculations, permissions and financial truth.
8. Future Accounting, Inventory and Reports remain compatible with the same event model, even while their navigation entries are placeholders.
9. Each migration phase receives automated parity/regression tests and updates to the repository continuity documents.


## 4. Current module inventory and target parity status

The repository inspection confirms that **no YODAMEN business module has yet been implemented in React, Laravel or MySQL**. The existing health endpoint and connection screen are infrastructure smoke tests only. This gives a clean baseline for the phased migration.

| Area | Module | Apps Script state | UI route/rules | React target status | Laravel target status | MySQL target status | Migration note |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | Dashboard | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Build after core read models exist |
| Operations | Customers | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Core CRUD + history |
| Operations | Bookings | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Core lifecycle |
| Operations | Queuing | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Same booking lifecycle |
| Operations | Servicing | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Core downstream event source |
| Operations | Service Balances | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Per Package Enrollment |
| Sales | Dashboard | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Parent-Sale transaction counts; service/customer rankings |
| Sales | Z-Reading | Read/report + HR close hook | Routed/defined | Not implemented | Not implemented | Absent | Target gets persistent close semantics |
| Sales | Transactions | Implemented with layered V33 logic | Routed/defined | Not implemented | Not implemented | Absent | Parent Pending, finalized line-level tabs |
| HR & Payroll | My Payroll | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Personnel-scoped |
| HR & Payroll | My Profit | Recovered implementation | Routed/defined | Not implemented | Not implemented | Absent | Partner-scoped |
| HR & Payroll | My Attendance | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Private proof |
| HR & Payroll | Personnel | Implemented | Routed/defined | Not implemented | Not implemented | Absent | User remains separate from Personnel |
| HR & Payroll | Attendance | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Approval/adjustment audit |
| HR & Payroll | Daily Compensation | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Hourly rate + configurable OT |
| HR & Payroll | Commission Summary | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Service-line identity |
| HR & Payroll | Cash Advances | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Employee CA ledger |
| HR & Payroll | Payroll | Implemented | Routed/defined | Not implemented | Not implemented | Absent | DRAFT -> APPROVED -> PAID |
| HR & Payroll | Payslips | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Frozen snapshot |
| HR & Payroll | Leave & Adjustments | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Approval workflow |
| Accounting | Profit Sharing | Recovered implementation | Routed/defined | Not implemented | Not implemented | Absent | Separate from Payroll |
| Accounting | AR / Collections / Expenses / Petty Cash / Bank Rec / Cash / Daily Closing / Cash Flow / FS | Placeholder/future | Target rules defined | Not implemented | Not implemented | Absent | Preserve navigation placeholders; activate later |
| Inventory | Dashboard / Master / Receive / Movements / Consumption / Count / Adjustments / Reorder | Placeholder/future | Target rules defined | Not implemented | Not implemented | Absent | Event-driven future modules |
| Reports | Sales / Customers / Services / Staff / HR / Accounting / Inventory | Placeholder/future | Target rules defined | Not implemented | Not implemented | Absent | Read-only projections |
| Admin | Business Setup | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Phase 1 foundation |
| Admin | Branding & Appearance | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Foundation/UI shell |
| Admin | Reference Lists | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Foundation |
| Admin | Services & Pricing | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Phase 1B dependency |
| Admin | Commission Rules | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Phase 6 |
| Admin | Users & Roles | Implemented | Routed/defined | Not implemented | Not implemented | Absent | Phase 1 security |
## 5. Current Apps Script architecture and dependency analysis

### 5.1 Overall architecture

The current system is a single Apps Script web application. `Index.html` includes multiple large HTML fragments into one browser document. `App.html` owns navigation and routing. UI fragments invoke Apps Script functions through `google.script.run`. Backend files share one global Apps Script namespace and depend heavily on common functions from `Database.js`, `Auth.js`, `Config.js`, `Audit.js` and HR helper functions.

`Database.js` performs complete sheet reads, converts row 1 to object keys and uses `appendRow()` for inserts. Updates generally locate a sheet row by a human-readable ID. This explains the many copied identifiers/names across Booking, Queue, Visit and Sales tables and the need for later consistency wrappers.

The current `Index.html` include order places `OperationsPerformanceHTML.html`, `SalesHTML.html`, `HRHTML.html`, `App.html`, `ProfitSharingHTML.html` and finally `UXPolish.html` in one global document. Later scripts can therefore replace earlier render functions. Static inspection found repeated global render/helper names across these UI files, confirming that load order is part of the effective implementation.

### 5.2 High-impact dependency graph


| Area | Current dependency | Observed behavior | Target replacement |
| --- | --- | --- | --- |
| App shell / frontend | Index.html -> included HTML -> App.html / UXPolish.html | Load order matters. Later files override global functions; several render functions exist in more than one HTML file. | React router/layout renders canonical components directly. |
| Shared persistence | Most backends -> Database.js | Full-sheet reads via getTableData(), appendRow writes, row/header lookup, no relational constraints. | Eloquent/SQL queries, indexed lookups, foreign keys, transactions. |
| Security | Admin, Operations, Sales, HR, Profit Sharing -> Auth.js | requirePermission_ is the server-side protection; sessions live in Apps Script CacheService. | Laravel auth + Policies/Gates/middleware; DB-backed roles/actions. |
| Operations | BookingVisits.js -> Database.js/Auth.js/Config.js; ServiceCredit.js calls BookingVisits actions | Booking, queue, visit, servicing and service-credit flows share duplicated sheet records. | Normalized Booking/Queue/Visit models with source FKs and domain actions. |
| Sales creation | Sales.js -> BookingVisits.js/ServiceCredit.js/Database.js | Completed visits are synchronized into Sales/SalesItems; current sheet implementation has race exposure. | CreateSaleFromCompletedVisit action in a DB transaction; unique sales.visit_id and source-key constraints. |
| Payment allocation | ServicingPaymentAllocation.js -> Sales/ServiceCredit/Database | V3-V5 allocation layer handles Advance, Checkout, Collection and PACKAGE_BALANCE redirection. | Canonical PaymentApplicationService and validation; payment ledger remains independent. |
| Sales finalization waterfall | SalesPayrollBridge.js -> Sales.js + ServicingPaymentAllocation.js + ServiceBalanceFinancial2026.js + Commission/HR helpers | Bridge wrappers coordinate payment allocation, package reconciliation and commission posting. | One FinalizeCheckout action orchestrates critical writes atomically, then emits after-commit events. |
| Settlement tabs / integrity | SalesServiceLineSettlement2026.js -> Sales/Allocations/Commission bridge | Implements parent Pending and service-line Paid/On Account/Service Credit views; V33 adds lock/repair. | SettlementLineReadModel; SQL uniqueness prevents duplicates; repair logic moves to import tooling. |
| Packages / credits | ServiceCredit.js + ServiceBalanceFinancial2026.js -> Sales/Booking/Visit tables | Entitlement, usage and financial monitoring are interwoven with sale/payment data. | PackageEnrollment, ServiceCredit ledger/usage, derived financial read model. |
| Commissions | CommissionMultiStaffFix.js + SalesPayrollBridge.js -> HRPayroll.js | Multi-staff reconciliation uses service-line source identity and fixes historical misses. | CommissionRuleResolver + ReconcileCommissions + append-style reversals with unique source identity. |
| Attendance / payroll | YodamenAttendanceV19.gs.js -> HRPayroll.js; HRPayroll.js -> Sales/Visits/Commissions | Attendance approval feeds daily compensation; payroll locks source rows and requires HR EOD markers. | Attendance domain, DailyCompensation, PayrollRun aggregate; explicit Z-reading close FK/check. |
| Profit sharing | ProfitSharing.js -> HRPayroll.js personnel helpers | Partner percentage snapshots and DRAFT/APPROVED/PAID lifecycle. | Independent ProfitSharing aggregate using Personnel; no dependency on payroll internals. |
| Z-reading | ZReadings.js -> Sales/Payments/Visits/Customers; HRPayroll has separate finalizeHrEodFromZReading_ hook | Current ZReading backend is read-only; payroll close hook exists elsewhere. | Persistent ZReading close aggregate; close action snapshots results and gates payroll. |
| Diagnostics / reset | BookingDiagnostic*, PaymentDebug, PaymentTraceDiagnostic, HRPayrollV9Diagnostics, DevelopmentReset | Operationally useful evidence, unsafe as normal production runtime. | PHPUnit tests, import validators, environment-guarded Artisan commands. |

### 5.3 Critical waterfall found in source

The effective legacy path for the financial workflow spans several files:

**BookingVisits / ServiceCredit -> Sales -> ServicingPaymentAllocation -> SalesPayrollBridge -> SalesServiceLineSettlement -> Commission/HRPayroll**

`Sales.js` remains the base Sale and Payment implementation. `ServicingPaymentAllocation.js` adds service-line allocation and package-balance financial applications. `SalesPayrollBridge.js` coordinates the finalization waterfall with package reconciliation and commission. `SalesServiceLineSettlement2026.js` supplies the newer parent Pending vs finalized line-level view and V33 integrity layer. `CommissionMultiStaffFix.js` and HR functions reconcile service-line commission behavior.

This chain should become a small number of explicit Laravel use cases. The most important replacement is a single `FinalizeCheckout` domain action that owns the atomic financial transaction and invokes focused services for allocation, package reconciliation and commission eligibility.

### 5.4 Compatibility/repair patterns to retire

The target should leave behind runtime function overrides, MutationObserver patches, V-number aliases, generic full-sheet scanning, `google.script.run`, ScriptLock-based correctness, browser global state, runtime permission bridges, duplicate-repair logic as normal business behavior, and production-accessible reset utilities.

Diagnostics and repair knowledge still have value. Booking/payment/HR diagnostic scripts become automated tests, import validators or environment-guarded Artisan commands. V33 duplicate-repair logic becomes legacy-import analysis, while SQL uniqueness prevents recurrence.


## 6. Current Google Sheets database analysis

Static inspection found **46 named operational/reference sheets** in the active source when the broad DevelopmentReset alias list is excluded. Some are setup/repair ledgers rather than normal business masters. The following map is the structural source inventory.

| Area | Current sheet | Current role | Target normalization |
| --- | --- | --- | --- |
| System/Admin | SYS_Config | Global/business settings; timezone, currency, default branch | Normalize into system_settings; some values move to businesses/branches |
| System/Admin | REF_Business | Business master | businesses |
| System/Admin | REF_Branches | Branch master | branches |
| System/Admin | REF_Branding | Brand/theme settings | branding_settings |
| System/Admin | REF_General | Generic reference categories and values | reference_groups + reference_values |
| System/Admin | REF_Roles | Roles | roles |
| System/Admin | REF_Permissions | Role/module/action matrix | permissions + role_permissions |
| System/Admin | DB_Users | User login/account data | users; link separately to personnel |
| System/Admin | DB_Audit_Log | Basic append log | audit_logs with richer snapshots/reasons/correlation |
| Customers | DB_Customers | Customer master | customers |
| Service master | DB_Services | Service master, service count/sessions | services + package_definitions where applicable |
| Service master | DB_ServicePrices | Price by service/branch/effective dates | service_prices |
| Service master | REF_CommissionRules | Commission rule hierarchy | commission_rules |
| Operations | DB_Bookings | Parent booking | bookings |
| Operations | DB_BookingServices | Booked service lines | booking_service_lines |
| Operations | DB_BookingRetailItems | Booked retail lines | booking_retail_lines |
| Operations | DB_Queue | Queue/check-in parent | queue_entries |
| Operations | DB_QueueServices | Queue service copies/state | queue_service_lines |
| Operations | DB_QueueRetailItems | Queue retail copies/state | queue_retail_lines |
| Operations | DB_Visits | Visit parent | visits |
| Operations | DB_VisitServices | Rendered service lines | visit_services |
| Operations | DB_VisitRetailItems | Visit retail lines | visit_retail_items |
| Operations | DB_ServiceCompletionEvents | Waterfall status record for completed services | service_completion_events / domain outbox links |
| Packages | DB_ServicePackageEnrollments | Purchase-level package enrollment and cached financial fields | package_enrollments; derive payment totals from applications |
| Packages | DB_ServiceCredits | Entitlement balance currently also supports cumulative stacking | service_credits one per enrollment |
| Packages | DB_ServiceCreditUsage | Session usage | service_credit_usage |
| Packages | DB_ServiceCreditLedger | Session movement audit | service_credit_ledger |
| Sales | DB_Sales | Parent sale plus cached payment totals/balance | sales; retain limited snapshots, derive payment truth |
| Sales | DB_SalesItems | Sale lines for service/package/service-credit/product | sale_items |
| Sales | DB_SalesPayments | Cash receipt ledger | payments |
| Sales | DB_ServicingPaymentAllocations | Manual line/package financial applications | payment_applications |
| Sales | DB_SalesRebookingLog | Rebooking audit and advance continuity | booking_rebookings |
| Sales | DB_SalesDuplicateArchive | V33 repair archive | migration exception/archive only; not normal runtime table |
| HR | DB_Employees | Personnel, employment, pay, partner and linkage fields | personnel + positions/reference values |
| HR | DB_Attendance | Attendance and proof/workflow columns | attendance_records + attendance_proofs + attendance_adjustments |
| HR | DB_DailyCompensation | Daily pay/commission snapshots | daily_compensations |
| HR | DB_CommissionEarnings | Commission ledger with source snapshots | commission_earnings |
| HR | DB_HRAdjustments | Leave/addition/deduction adjustments | hr_adjustments + leave_requests |
| HR | DB_CashAdvances | Employee cash advance master | cash_advances |
| HR | DB_CashAdvanceLedger | Employee cash advance ledger | cash_advance_ledger |
| HR | DB_CashAdvancePayrollAllocations | Payroll deductions applied to advances | cash_advance_payroll_allocations |
| HR | DB_PayrollRuns | Payroll header | payroll_runs |
| HR | DB_PayrollLines | Payroll employee lines | payroll_lines |
| HR | DB_HREodSummary | Payroll-facing date-close and daily compensation snapshots | z_readings/eod close + optional payroll close snapshots |
| Profit Sharing | DB_ProfitSharingRuns | Profit sharing header | profit_sharing_runs |
| Profit Sharing | DB_ProfitSharingLines | Partner allocations/snapshots | profit_sharing_lines |

### 6.1 Main structural problems created by Sheets

- IDs are strings copied between tables instead of true foreign keys.
- Booking, Queue and Visit service rows repeat customer/service/staff names and source fields because there is no relational join enforcement.
- Sales and Package Enrollment rows cache totals that later payment-allocation layers can independently change.
- Permission, reference and status strings rely on normalization helpers because casing/whitespace can drift.
- Full-sheet scans make race checks non-atomic. The duplicate Sale incident is a direct example.
- Setup scripts mutate headers and seed rows at runtime. Laravel migrations and seeders should replace that behavior.
- Spreadsheet row order and row numbers currently matter to updates. SQL records should use stable primary keys and indexed business codes.


## 7. Proposed MySQL architecture

### 7.1 General database conventions

- Use InnoDB and foreign keys for relational domain tables.
- Use internal bigint IDs for joins and immutable human-readable `code` columns for YODAMEN display IDs.
- Retain imported legacy codes exactly and index them uniquely.
- Use `DECIMAL(14,2)` or an equivalent disciplined Money value object for peso amounts. PHP floating-point values should never be stored as financial truth.
- Use UTC for timestamp instants when deployment allows it, with explicit local `business_date` fields for day-bound rules. Display and business-date calculation use Asia/Manila.
- Add `business_id` and `branch_id` where scope requires them.
- Prefer append-style ledgers for Payments, Payment Applications/reversals, service-credit movements, cash advances, inventory movements, audits and corrections.
- Add database timestamps plus actor IDs for important mutations; finalized snapshots keep dedicated finalized/approved/paid timestamps.
- Enums/value objects in Laravel define lifecycle/status values so free-text status drift disappears.

### 7.2 Proposed table catalog


| Domain | Table | Key fields | Key integrity |
| --- | --- | --- | --- |
| Foundation | businesses | id, code, name, legal_name, timezone, currency, status | code UNIQUE |
| Foundation | branches | id, business_id, code, name, address, status | FK business; UNIQUE (business_id, code) |
| Foundation | system_settings | id, business_id, key, value_json | UNIQUE (business_id, key) |
| Foundation | branding_settings | id, business_id, key, value | UNIQUE (business_id, key) |
| Foundation | reference_groups | id, business_id, code, name | UNIQUE (business_id, code) |
| Foundation | reference_values | id, group_id, code, display_value, sort_order, active | FK group; UNIQUE (group_id, code) |
| Foundation | roles | id, business_id, code, name, protected, active | UNIQUE (business_id, code) |
| Foundation | permissions | id, module, action, description | UNIQUE (module, action) |
| Foundation | role_permissions | role_id, permission_id, allowed | FKs; UNIQUE (role_id, permission_id) |
| Foundation | users | id, business_id, role_id, username, email nullable, password, status, force_reset, failed_attempts, locked_until, last_login_at | UNIQUE (business_id, username); email nullable/unique policy after repo inspection |
| Foundation | code_sequences | id, business_id, entity_type, period_key, next_value | UNIQUE (business_id, entity_type, period_key) |
| Foundation | audit_logs | id, business_id, actor_user_id, action, entity_type, entity_id, entity_code, before_json, after_json, reason, correlation_id, idempotency_key, ip, user_agent, created_at | Append-only indexes on entity, actor, correlation |
| Foundation | idempotency_requests | id, business_id, user_id, key, route, request_hash, response_status, response_body, completed_at | UNIQUE (business_id, key) |
| Foundation | outbox_events | id, event_type, aggregate_type, aggregate_id, payload_json, occurred_at, processed_at, attempts | Reliable after-commit downstream jobs |
| Customers | customers | id, code, business_id, first_name, last_name, mobile, email, birthday, gender, address, lead_source_id/value, registered_at, status, notes | code UNIQUE; indexed mobile/email |
| Services | service_categories | id, business_id, code, name, active | UNIQUE (business_id, code) |
| Services | services | id, code, business_id, category_id, name, duration_minutes, description, status | code UNIQUE; FK category |
| Services | service_prices | id, service_id, branch_id nullable, amount, effective_from, effective_until, status | FKs; prevent overlapping active price ranges in service logic |
| Services | package_definitions | id, code, service_id, session_count, status, effective_from, effective_until | code UNIQUE; FK service |
| Retail/Core | products | id, code, business_id, name, category_id nullable, status | Minimal master now; inventory fields later |
| Retail/Core | product_prices | id, product_id, branch_id nullable, amount, effective_from, effective_until, status | FKs |
| Operations | bookings | id, code, business_id, branch_id, customer_id, customer_type, scheduled_date, scheduled_time, source, status, notes, created_by | code UNIQUE; FKs business/branch/customer/user |
| Operations | booking_service_lines | id, code, booking_id, service_id, service_source, quantity, assigned_personnel_id nullable, service_credit_id nullable, package_enrollment_id nullable, name_snapshot, notes | code UNIQUE; FKs; check quantity > 0 |
| Operations | booking_retail_lines | id, code, booking_id, product_id, quantity, unit_price_snapshot, name_snapshot, notes | code UNIQUE; FKs |
| Operations | booking_rebookings | id, code, booking_id, old_date, old_time, new_date, new_time, reason, actor_user_id, created_at | Append-only; code UNIQUE |
| Operations | queue_entries | id, code, booking_id, customer_id, queue_date, check_in_at, source, status, visit_id nullable, notes | code UNIQUE; FK booking/customer; one active queue entry per booking occurrence |
| Operations | queue_service_lines | id, code, queue_entry_id, booking_service_line_id, service_id, assigned_personnel_id nullable, source, status, snapshot fields | code UNIQUE; FKs |
| Operations | queue_retail_lines | id, code, queue_entry_id, booking_retail_line_id, product_id, quantity, snapshot fields | code UNIQUE; FKs |
| Operations | visits | id, code, business_id, branch_id, booking_id, queue_entry_id nullable, customer_id, visit_date, check_in_at, service_start_at, check_out_at, status, notes | code UNIQUE; FKs; queue_entry_id UNIQUE when present |
| Operations | visit_services | id, code, visit_id, booking_service_line_id nullable, queue_service_line_id nullable, service_id, assigned_personnel_id, service_source, service_credit_id nullable, package_enrollment_id nullable, quantity, status, started_at, completed_at | code UNIQUE; FKs; durable source relation |
| Operations | visit_retail_items | id, code, visit_id, booking_retail_line_id nullable, product_id, quantity, unit_price_snapshot | code UNIQUE; FKs |
| Operations | service_completion_events | id, code, visit_service_id, event_type, version_no, occurred_at, actor_user_id, source_snapshot_json | Append-only; UNIQUE (visit_service_id, event_type, version_no) |
| Packages | package_enrollments | id, code, customer_id, package_definition_id, service_id, source_sale_id nullable, source_sale_item_id nullable, total_sessions_snapshot, package_price_snapshot, status, activated_at, cancelled_at | code UNIQUE; FKs; source_sale_item_id UNIQUE once activated |
| Packages | service_credits | id, code, package_enrollment_id, customer_id, service_id, sessions_purchased, expiry_date, status | code UNIQUE; package_enrollment_id UNIQUE |
| Packages | service_credit_ledger | id, code, service_credit_id, package_enrollment_id, movement_type, sessions, booking_id nullable, visit_id nullable, visit_service_id nullable, sale_id nullable, sale_item_id nullable, reason, occurred_at | Append-only; code UNIQUE |
| Packages | service_credit_usage | id, code, service_credit_id, package_enrollment_id, visit_service_id, sessions_used, used_at | code UNIQUE; visit_service_id UNIQUE for single entitlement use; FKs |
| Sales | sales | id, code, business_id, branch_id, visit_id, booking_id, customer_id, sales_date, gross_amount_snapshot, status, finalized_at, cancelled_at, reversal_of_id nullable, notes | code UNIQUE; visit_id UNIQUE; FKs |
| Sales | sale_items | id, code, sale_id, source_key, item_type, visit_service_id nullable, visit_retail_item_id nullable, package_enrollment_id nullable, item_id/service_id/product_id, item_name_snapshot, service_source, quantity, unit_price, line_total, status, finalized_at | code UNIQUE; UNIQUE (sale_id, source_key); explicit source FKs |
| Sales | payments | id, code, business_id, customer_id, booking_id, sale_id nullable, payment_type, amount, payment_method_id/value, reference_no, cash_account_id nullable, received_at, status, idempotency_key, created_by | code UNIQUE; idempotency_key UNIQUE for financial submission; amount > 0 |
| Sales | payment_applications | id, code, payment_id, sale_id nullable, target_type, target_sale_item_id nullable, package_enrollment_id nullable, source_sale_id nullable, amount, allocation_order, context, status, snapshots_json, created_by | code UNIQUE; FKs; target validation; duplicate-active target protection |
| Sales | payment_application_reversals | id, code, payment_application_id, amount, reason, created_by, created_at | Append-only; cannot exceed active applied amount |
| Sales/Future | refunds | id, code, payment_id, amount, reason, status, approved_by nullable, created_by, created_at | Original payment preserved; code UNIQUE |
| Sales | z_readings | id, code, business_id, branch_id, business_date, status, gross_sales_snapshot, total_collected_snapshot, summary_json, closed_by, closed_at, reopened_by nullable, reopened_at nullable, reopen_reason nullable | UNIQUE (branch_id, business_date) for active close policy |
| HR | positions | id, business_id, code, name, active | UNIQUE (business_id, code) |
| HR | personnel | id, code, business_id, branch_id, user_id nullable, position_id, personnel_type, employment_type, names/contact, hourly_rate, ot_entitled, ot_multiplier, compensation_rule, guaranteed_daily_pay, can_perform_services, commission_eligible, profit_sharing_eligible, profit_sharing_pct, effective dates, hire_date, status | code UNIQUE; user_id UNIQUE nullable; FKs |
| HR | attendance_records | id, code, personnel_id, attendance_date, status, workflow_status, time_in_at, time_out_at, worked_hours_snapshot, ot_hours_snapshot, source, approved_by/at, notes | UNIQUE active record per personnel/date policy; FKs |
| HR | attendance_proofs | id, attendance_id, proof_type, object_key, original_name, mime_type, size_bytes, captured_at, uploaded_by | Private storage; FK attendance |
| HR | attendance_adjustments | id, code, attendance_id, original_values_json, corrected_values_json, reason, adjusted_by, adjusted_at | Append-only |
| HR | daily_compensations | id, code, personnel_id, attendance_id, work_date, hourly_rate_snapshot, ot_multiplier_snapshot, compensation_rule_snapshot, guaranteed_daily_pay_snapshot, worked_hours, ot_hours, basic_pay, ot_premium, confirmed_commission_snapshot, guaranteed_top_up, gross_daily_projection, payroll_run_id nullable, status | UNIQUE (personnel_id, work_date) while open; source snapshots freeze when payroll locks |
| HR | commission_rules | id, code, business_id, branch_id nullable, applies_to, personnel_id nullable, position_id nullable, service_id nullable, service_category_id nullable, method, rate_value, basis, effective_from/until, priority, status | code UNIQUE; hierarchy resolved server-side |
| HR | commission_earnings | id, code, sale_id, sale_item_id, visit_service_id, personnel_id, service_id, commission_rule_id nullable, basis_amount_snapshot, rule_snapshot_json, commission_amount, earned_date, status, reversed_earning_id nullable, payroll_run_id nullable | code UNIQUE; UNIQUE source identity for original earning |
| HR | hr_adjustments | id, code, personnel_id, adjustment_date, adjustment_type, effect, amount, status, approved_by/at, payroll_run_id nullable, notes | code UNIQUE; append/freeze rules |
| HR | leave_requests | id, code, personnel_id, start_date, end_date, leave_type, paid_leave, status, reason, approved_by/at | code UNIQUE |
| HR | cash_advances | id, code, personnel_id, request_date, release_date, original_amount, release_method, deduction_method, deduction_per_payroll, deduction_start_date, status, approved_by/at, notes | code UNIQUE; original_amount immutable after release |
| HR | cash_advance_ledger | id, code, cash_advance_id, personnel_id, transaction_date, transaction_type, amount, payroll_run_id nullable, payment_method, reference_no, notes | Append-only; code UNIQUE |
| HR | cash_advance_payroll_allocations | id, code, cash_advance_id, payroll_run_id, payroll_line_id, personnel_id, amount, status | UNIQUE source allocation policy; FKs |
| HR | payroll_runs | id, code, business_id, period_start, period_end, pay_date, status, totals, notes, created_by/at, approved_by/at, paid_by/at | code UNIQUE; prevent overlapping non-cancelled periods by service validation + locking |
| HR | payroll_lines | id, code, payroll_run_id, personnel_id, employee_name_snapshot, position_snapshot, branch_snapshot, compensation/commission/addition/deduction totals, gross_pay, net_pay, payslip_no, status | UNIQUE (payroll_run_id, personnel_id); code UNIQUE |
| HR | payroll_line_sources | id, payroll_line_id, source_type, source_id, source_key, amount_snapshot, metadata_json | UNIQUE active source_key across payroll; trace to compensation/commission/adjustment/CA deduction |
| HR | payslip_snapshots | id, payroll_line_id, version_no, snapshot_json, generated_at | Immutable after Approved/Paid |
| Profit Sharing | profit_sharing_runs | id, code, business_id, period_start, period_end, profit_base, basis_source, status, partner_count, total_allocated, retained_balance, notes, audit timestamps/users | code UNIQUE |
| Profit Sharing | profit_sharing_lines | id, code, run_id, personnel_id, partner_name_snapshot, position_snapshot, percentage_snapshot, profit_base_snapshot, share_amount, status, paid_at | UNIQUE (run_id, personnel_id); code UNIQUE |
| Future Accounting | cash_accounts | id, code, business_id, account_type, name, active | code UNIQUE |
| Future Accounting | chart_of_accounts | id, code, business_id, account_no, name, account_type, parent_id nullable, active | UNIQUE (business_id, account_no) |
| Future Accounting | accounting_mappings | id, business_id, event_type, debit_account_id, credit_account_id, effective dates, active | No hard-coded account IDs in controllers |
| Future Accounting | journal_entries / journal_lines | header + balanced debit/credit lines linked to source event | Append-only approved entries |
| Future Accounting | receivables | id, source_sale_id/source_obligation_id, due_date, original_amount, status | Derived/posted from confirmed obligation; no double-counting |
| Future Accounting | expenses / expense_lines | expense header, vendor/payee, date, status + classified lines | Auditable void/reversal |
| Future Accounting | bank_statement_lines / bank_reconciliations | imported bank lines and matches | Append-only match/adjustment trail |
| Future Accounting | petty_cash_ledger / daily_closes | cash fund movements and close snapshots | Ledger-based balance |
| Future Inventory | inventory_items | id, code, business_id, category, unit, reference_cost, active | code UNIQUE |
| Future Inventory | inventory_locations | id, branch_id, code, name, active | Optional if more than one stock location |
| Future Inventory | inventory_receipts / inventory_receipt_lines | receipt header/lines | Approved receipt generates movements |
| Future Inventory | inventory_movements | id, code, item_id, location_id, movement_type, quantity, unit_cost_snapshot, source_type/id, occurred_at | Append-only; source idempotency |
| Future Inventory | service_recipes / service_recipe_lines | versioned service BOM and item quantities with effective dates | Completion snapshots active recipe |
| Future Inventory | inventory_counts / inventory_count_lines | count sessions, expected/actual/variance | Approved variance creates movement |
| Future Inventory | reorder_settings | item/location reorder point and preferred supplier | UNIQUE item/location |

### 7.3 Core uniqueness and concurrency constraints

The following protections are migration-critical:

- `sales.visit_id` unique.
- `sales.code`, `sale_items.code`, `payments.code`, package/booking/visit/payroll/profit-sharing codes unique.
- `sale_items (sale_id, source_key)` unique, with deterministic source keys such as the originating Visit Service/Visit Retail/Package Enrollment identity.
- `service_credits.package_enrollment_id` unique.
- `service_credit_usage.visit_service_id` unique where one completed line consumes one entitlement source.
- Original `commission_earnings` receive a unique source identity based on Sale Item/Visit Service + Personnel + commission type.
- `profit_sharing_lines (run_id, personnel_id)` unique.
- Payroll source usage has an active unique `source_key` so compensation/commission/adjustment rows cannot enter two active payrolls.
- Financial POST requests use a persistent idempotency key and request hash. A retry returns the original result rather than creating another payment.
- Domain actions use `DB::transaction()` and `lockForUpdate()` on mutable financial obligations before validating capacities and writing applications.


## 8. Table relationships and foreign keys

| Parent/source | Relationship | Rule |
| --- | --- | --- |
| Business | has many Branches, Users, Personnel, Customers, Services, Roles, Settings | All business-scoped records carry business_id. |
| User | belongs to Business and Role; optionally links 1:1 to Personnel | Account identity is distinct from employment/partner identity. |
| Role | many-to-many Permissions through role_permissions | Authorization uses module + action. |
| Customer | has many Bookings, Visits, Sales, PackageEnrollments, Payments | Customer history becomes a read model over these relations. |
| Booking | belongs to Customer/Branch; has service/retail lines; has rebooking history; may have Advance Payments | Advance may exist before Sale. |
| QueueEntry | belongs to Booking; carries queue service/retail state; points to Visit when checked in | Walk-in still owns a Booking. |
| Visit | belongs to Booking/Queue/Customer; has many VisitServices and retail items | One Visit maps to at most one Sale. |
| VisitService | belongs to Visit, Service, assigned Personnel; may point to PackageEnrollment/ServiceCredit | Completion drives operational downstream effects. |
| Sale | belongs to Visit/Booking/Customer; has many SaleItems and Payments | sales.visit_id UNIQUE. |
| SaleItem | belongs to Sale; points to exactly one durable operational source where applicable | source_key prevents duplicate import/runtime creation. |
| Payment | belongs to Customer/Booking and optionally current Sale; has many PaymentApplications | Money received may predate Sale or settle another package/source obligation. |
| PaymentApplication | belongs to Payment; targets current SaleItem or PackageEnrollment/source Sale | Applications determine balances; redirects are explicit. |
| PackageEnrollment | belongs to Customer/PackageDefinition/Service; eventually points to source SaleItem | Each purchase stays separate. |
| ServiceCredit | belongs 1:1 to PackageEnrollment; usage points to VisitService | No cumulative cross-enrollment balance in target. |
| CommissionEarning | belongs to SaleItem, VisitService, Personnel, Service and optional CommissionRule | Unique source identity prevents duplicate earnings. |
| AttendanceRecord | belongs to Personnel; has private proofs and adjustment history | Approved/present attendance feeds DailyCompensation. |
| DailyCompensation | belongs to Personnel/Attendance; may be locked to PayrollRun | Open rows may recalc; locked rows freeze. |
| PayrollRun | has many PayrollLines; lines have source links to compensation/commission/adjustments/cash-advance deductions | A source cannot enter two active payroll runs. |
| ProfitSharingRun | has many ProfitSharingLines; each line belongs to Partner Personnel | UNIQUE partner per run and snapshot percentage. |
| ZReading | belongs to Business/Branch/date; snapshots closed day totals | Payroll validates persistent close records. |
| AuditLog | belongs to actor User and references any business entity by typed reference | Append-only business audit. |

## 9. Stored data vs calculated data

| Treatment | Data | Reason |
| --- | --- | --- |
| Store as source-of-truth | Immutable/display codes and legacy IDs | Needed for continuity, reconciliation and audit. |
| Store as source-of-truth | Booking/queue/visit/service status transitions and timestamps | Operational state machine. |
| Store as source-of-truth | Sale line quantity, unit price, line total and item/service snapshots at financial transaction time | Historical prices must not change when master price changes. |
| Store as source-of-truth | Payment amount/type/method/reference/date/status | Cash ledger truth. |
| Store as source-of-truth | Payment Application amount/target/context and reversals | Settlement truth. |
| Store as source-of-truth | Package enrollment purchase snapshot, entitlement movements and session usage | Purchase and operational entitlement history. |
| Store as source-of-truth | Commission rule/basis/rate/amount snapshots on each earning | Frozen financial/HR history. |
| Store as source-of-truth | Attendance proof metadata, server timestamps, approval/adjustment records | Privacy/audit. |
| Store as source-of-truth | Daily compensation snapshot when generated, then freeze once attached to payroll | Payroll reproducibility. |
| Store as source-of-truth | Payroll/Profit Sharing run and line snapshots, approval/payment timestamps | Approved/Paid history must remain fixed. |
| Store as source-of-truth | Audit/reversal/refund/adjustment events | Historical traceability. |
| Calculate/derive | Sale Advance Payments, Checkout Payments, Total Paid, Outstanding Balance | Sum valid Payments/Applications. Cached columns are optional only when transactionally reconciled. |
| Calculate/derive | Sale-item current applied amount, balance, and settlement classification | SERVICE_CREDIT by source; otherwise PAID when balance zero, ON_ACCOUNT when balance remains. |
| Calculate/derive | Package Total Paid and Balance | Package price snapshot less valid Payment Applications targeting the enrollment/source obligation. |
| Calculate/derive | Package Sessions Used and Remaining | From entitlement ledger/usage, independent of money paid. |
| Calculate/derive | Accounts Receivable and aging | From finalized obligations less valid applications/refunds, with due-date policy. |
| Calculate/derive | Customer spend/frequency/last visit and all dashboards/rankings | Read models/queries. |
| Calculate/derive | Inventory on hand | Sum append-only Inventory Movements once inventory is active. |
| Calculate/derive | Cash/bank balances | Ledger/posting totals once accounting is active. |
| Calculate while open, then snapshot/freeze | Daily compensation, payroll draft, profit-sharing draft | Recalculation allowed only in DRAFT/open state. |

## 9A. Verified target repository map before migration

```text
backend/
  app/
    Http/Controllers/Controller.php
    Models/User.php
    Providers/AppServiceProvider.php
  bootstrap/app.php
  config/{app,auth,database,filesystems,queue,sanctum,session,...}.php
  database/
    migrations/  # framework/auth migrations only
    seeders/DatabaseSeeder.php
  routes/{api,web,console}.php
  tests/{Feature,Unit}/  # template tests only
  composer.json / composer.lock

frontend/
  src/
    App.jsx       # backend health-check screen only
    main.jsx
    App.css
    index.css
  package.json / package-lock.json
  vite.config.js
```

There are currently no YODAMEN domain folders to preserve. The migration may introduce conventional feature/domain folders after blueprint approval, while retaining the existing separate `backend/` and `frontend/` project split. Generated `backend/public/build/*` artifacts are not architectural source.

## 10. Laravel model map

| Domain | Models | Relationship/behavior note |
| --- | --- | --- |
| Foundation/Admin | Business, Branch, SystemSetting, BrandingSetting, ReferenceGroup, ReferenceValue, Role, Permission, User, CodeSequence, AuditLog, IdempotencyRequest, OutboxEvent | Business owns branch/user/reference/config data; User links to Personnel, remains a separate identity. |
| Customers/Services | Customer, ServiceCategory, Service, ServicePrice, PackageDefinition, Product, ProductPrice | Prices are effective-dated; transaction lines retain price/name snapshots. |
| Operations | Booking, BookingServiceLine, BookingRetailLine, BookingRebooking, QueueEntry, QueueServiceLine, QueueRetailLine, Visit, VisitService, VisitRetailItem, ServiceCompletionEvent | Source relations preserve Booking -> Queue -> Visit lineage. |
| Packages | PackageEnrollment, ServiceCredit, ServiceCreditLedgerEntry, ServiceCreditUsage | Enrollment is the purchase-level container; credit usage never determines payment status. |
| Sales | Sale, SaleItem, Payment, PaymentApplication, PaymentApplicationReversal, Refund, ZReading | Payment is cash truth; PaymentApplication is settlement truth; line status is derived from applications/source type. |
| HR | Position, Personnel, AttendanceRecord, AttendanceProof, AttendanceAdjustment, DailyCompensation, CommissionRule, CommissionEarning, HrAdjustment, LeaveRequest, CashAdvance, CashAdvanceLedgerEntry, CashAdvancePayrollAllocation, PayrollRun, PayrollLine, PayrollLineSource, PayslipSnapshot | Personnel owns employment/pay settings; User only provides account identity/access. |
| Profit Sharing | ProfitSharingRun, ProfitSharingLine | Independent lifecycle from Payroll; line snapshots percentage at period end. |
| Future Accounting | CashAccount, ChartOfAccount, AccountingMapping, JournalEntry, JournalLine, Receivable, Expense, ExpenseLine, BankStatementLine, BankReconciliation, PettyCashLedgerEntry, DailyClose | Activated after parity; event mappings configurable. |
| Future Inventory | InventoryItem, InventoryLocation, InventoryReceipt, InventoryReceiptLine, InventoryMovement, ServiceRecipe, ServiceRecipeLine, InventoryCount, InventoryCountLine, ReorderSetting | Movement ledger is source of stock truth. |

## 11. Laravel controller and service/action map

Controllers should remain thin. Form Requests validate payload shape, Policies/Gates authorize the actor, and domain actions/services own status transitions and transaction boundaries.


| Module | Controller/read endpoint layer | Responsibility | Domain services/actions |
| --- | --- | --- | --- |
| Auth / bootstrap | AuthController, BootstrapController | Authenticate, logout, me, change access code/password, return business/branding/navigation/permissions | AuthenticationService, AuthorizationService, BootstrapService |
| Customers | CustomerController, CustomerHistoryController | CRUD, search, customer history | CustomerService, CustomerHistoryReadModel |
| Services/Admin | ServiceController, ServicePriceController, ReferenceController | Service/price/reference maintenance | ServicePricingService, ReferenceService |
| Bookings | BookingController, BookingRebookingController | Create/update/cancel/rebook booking, attach advance | CreateBooking, UpdateBooking, CancelBooking, RebookBooking |
| Queue / walk-ins | QueueController, WalkInController | Check-in, waiting list, create walk-in through Booking lifecycle | CheckInBooking, CreateWalkInBooking, QueueService |
| Visits / servicing | VisitController, VisitServiceController, VisitRetailController | Start visit, assign staff, add/claim/start/complete/cancel service, retail items | StartVisit, AssignVisitServiceStaff, ClaimServiceCredit, CompleteVisitService, CancelVisitService, CompleteVisit |
| Service balances | ServiceBalanceController, PackageEnrollmentController | Enrollment-level balances and payment history | ServiceBalanceReadModel, PackageFinancialReadModel |
| Sales transactions | TransactionController, CheckoutController | Pending parents, finalized settlement lines, checkout detail/finalize | CreateSaleFromCompletedVisit, BuildCheckout, FinalizeCheckout, SettlementLineReadModel, ReconcileSale |
| Payments | BookingAdvancePaymentController, CollectionController, PaymentController | Receive advance, receive checkout payment inside finalization, later On Account collection, trace payments | ReceiveAdvance, AllocatePayment, CollectOnAccount, ApplyPaymentToPackage |
| Packages / credits | PackageEnrollmentController, ServiceCreditController | Activation, usage/history, reconciliation | ActivatePackage, ConsumeServiceCredit, ReconcilePackageBalance |
| Sales dashboard | SalesDashboardController | KPI/ranking reads | SalesDashboardReadModel |
| Z-Reading | ZReadingController | Preview, close, reopen under permission/reason, retrieve snapshot | BuildZReading, CloseZReading, ReopenZReading |
| Personnel | PersonnelController | CRUD and employment/pay configuration | PersonnelService |
| Attendance | MyAttendanceController, AttendanceAdminController | Self time in/out with proof, admin approval/adjustment | SubmitTimeIn, SubmitTimeOut, ApproveAttendance, AdjustAttendance, AttendanceStorageService |
| Daily compensation | DailyCompensationController | List/recalculate open daily comp | CalculateDailyCompensation, ReconcileDailyCompensation |
| Commission | CommissionRuleController, CommissionController | Rule CRUD/toggle, earning reads/reconcile | ResolveCommissionRule, ReconcileCommissions, ReverseCommission |
| Cash advances | CashAdvanceController | Request/release/approve/cancel, ledger reads | CashAdvanceService, AllocateCashAdvanceDeduction |
| Payroll | PayrollRunController, MyPayrollController, PayslipController | Validate period, generate, recalc draft, approve, mark paid, personal views | GeneratePayrollRun, RecalculatePayrollRun, ApprovePayrollRun, PayPayrollRun, PayrollSourceLocker |
| Leave / adjustments | LeaveController, HrAdjustmentController | Request/approve leave and adjustments | LeaveService, HrAdjustmentService |
| Profit sharing | ProfitSharingRunController, MyProfitController | Generate/recalc/cancel/approve/pay, personal view | GenerateProfitSharing, RecalculateProfitSharing, ApproveProfitSharing, PayProfitSharing |
| Admin security | UserController, RoleController, PermissionController | User CRUD/reset, role/action matrix | UserAdministrationService, RolePermissionService |
| Admin settings | BusinessController, BranchController, BrandingController | Business, branches, branding | BusinessSetupService, BrandingService |
| Audit | AuditController | Filtered read-only audit viewer | AuditService; writes occur through domain actions |
| Future Accounting | Accounting controllers by resource/read model | AR, collections, expenses, cash/bank, statements | PostingService + event-specific accounting actions |
| Future Inventory | Inventory controllers by resource/read model | Receipts, movements, recipes, counts, reorder | InventoryMovementService + ConsumeServiceMaterials |

## 12. Proposed API endpoint map

The current Laravel repository has only `/api/health`, so `/api/v1` is available as a clean proposed versioned namespace. Financial mutation endpoints should accept a persistent `Idempotency-Key` header and enforce idempotency server-side.


| Area | Endpoint | Authorization | Behavior |
| --- | --- | --- | --- |
| Auth | POST /api/v1/auth/login | Public | Login with username + access code/password; rate limited |
| Auth | POST /api/v1/auth/logout | Authenticated | Invalidate session/token |
| Auth | GET /api/v1/auth/me | Authenticated | User, linked personnel, role, permissions |
| Auth | POST /api/v1/auth/change-access-code | Authenticated | Re-hash using Laravel Hash; audit |
| Bootstrap | GET /api/v1/bootstrap | Authenticated | Business/branch/branding/reference/navigation bootstrap |
| Customers | GET /api/v1/customers | customers:VIEW | Paginated/filterable list |
| Customers | POST /api/v1/customers | customers:CREATE | Create customer |
| Customers | GET /api/v1/customers/{customer} | customers:VIEW | Detail |
| Customers | PATCH /api/v1/customers/{customer} | customers:EDIT | Update |
| Customers | GET /api/v1/customers/{customer}/history | customers:VIEW | Visit/sales/package history read model |
| Services | GET /api/v1/services | services-pricing:VIEW | Service catalog/prices |
| Services | POST /api/v1/services | services-pricing:CREATE | Create service |
| Services | PATCH /api/v1/services/{service} | services-pricing:EDIT | Edit service |
| Services | POST /api/v1/services/{service}/prices | services-pricing:CHANGE_PRICE | Add effective price |
| Bookings | GET /api/v1/bookings | bookings:VIEW | List/filter |
| Bookings | POST /api/v1/bookings | bookings:CREATE | Create booking and lines |
| Bookings | GET /api/v1/bookings/{booking} | bookings:VIEW | Detail/workspace |
| Bookings | PATCH /api/v1/bookings/{booking} | bookings:EDIT | Update allowed pending fields |
| Bookings | POST /api/v1/bookings/{booking}/rebook | bookings:EDIT | Append rebooking log; keep advance |
| Bookings | POST /api/v1/bookings/{booking}/cancel | bookings:EDIT | Controlled cancellation |
| Bookings | POST /api/v1/bookings/{booking}/advance-payments | bookings:CREATE + payment authorization | Receive actual Advance; Idempotency-Key required |
| Queue | GET /api/v1/queue | queue:VIEW | Waiting list |
| Queue | POST /api/v1/bookings/{booking}/check-in | queue:CREATE | Create queue/visit lineage |
| Queue | POST /api/v1/walk-ins | queue:CREATE + bookings:CREATE | Creates Booking first, then queue; optional Advance obeys payment permission |
| Queue | PATCH /api/v1/queue/{queueEntry} | queue:EDIT | Allowed queue edits |
| Visits | GET /api/v1/visits/today | visits:VIEW | Today list |
| Visits | GET /api/v1/visits/{visit} | visits:VIEW | Workspace |
| Visits | POST /api/v1/visits/{visit}/start | visits:EDIT | Start visit |
| Visit services | POST /api/v1/visits/{visit}/services | servicing:EDIT | Add service line |
| Visit services | PATCH /api/v1/visit-services/{visitService}/staff | servicing:EDIT | Assign one staff |
| Visit services | POST /api/v1/visit-services/{visitService}/start | servicing:EDIT | Start service |
| Visit services | POST /api/v1/visit-services/{visitService}/claim-service-credit | servicing:EDIT | Bind valid enrollment/credit |
| Visit services | POST /api/v1/visit-services/{visitService}/complete | servicing:COMPLETE | Operational completion; no commission yet |
| Visit services | POST /api/v1/visit-services/{visitService}/cancel | servicing:EDIT | Controlled cancellation/reversal effects |
| Visits | POST /api/v1/visits/{visit}/complete | visits:COMPLETE | Complete visit and ensure one draft Sale |
| Sales | GET /api/v1/sales/pending | sales:VIEW | One parent row per pending Sale/Booking |
| Sales | GET /api/v1/sales/settlement-lines?status=PAID\|ON_ACCOUNT\|SERVICE_CREDIT | sales:VIEW | Derived finalized line tabs |
| Sales | GET /api/v1/sales/{sale}/checkout | sales:VIEW | Lines, previous/advance payments, capacities, projected balances |
| Sales | PUT /api/v1/sales/{sale}/advance-allocations | sales:EDIT | Validate/save pending allocation state if UI needs pre-finalization persistence |
| Sales | POST /api/v1/sales/{sale}/finalize-checkout | sales:EDIT | Atomic finalization; Idempotency-Key required |
| Sales | POST /api/v1/sales/{sale}/confirm-covered | sales:EDIT | Finalize when prior valid payments fully cover current charge |
| Sales | POST /api/v1/sales/{sale}/collections | sales:EDIT | Later On Account collection + manual allocation; Idempotency-Key required |
| Sales | GET /api/v1/sales/{sale}/summary | sales:VIEW | Trace parent, lines, payments, applications |
| Payments | GET /api/v1/payments | payments:VIEW | Cash ledger |
| Payments | GET /api/v1/payments/{payment} | payments:VIEW | Payment + applications/reversals |
| Service balances | GET /api/v1/service-balances | service-balances:VIEW | One row per PackageEnrollment |
| Service balances | GET /api/v1/package-enrollments/{enrollment}/financials | service-balances:VIEW | Price, paid, balance, sessions, payment/application history |
| Packages | GET /api/v1/customers/{customer}/service-credits | packages:VIEW | Available enrollment-specific credits |
| Sales dashboard | GET /api/v1/sales-dashboard | sales-dashboard:VIEW | KPIs and rankings |
| Z-Reading | GET /api/v1/z-readings/preview?date=YYYY-MM-DD | sales-dashboard:VIEW | Read-only preview from finalized sales/received payments |
| Z-Reading | POST /api/v1/z-readings/close | z-reading close permission | Persistent close snapshot; server time; Idempotency-Key |
| Z-Reading | GET /api/v1/z-readings/{date} | sales-dashboard:VIEW | Closed snapshot/detail |
| Z-Reading | POST /api/v1/z-readings/{zReading}/reopen | special approve permission | Reason required, audited |
| Personnel | GET /api/v1/personnel | personnel:VIEW | List |
| Personnel | POST /api/v1/personnel | personnel:CREATE | Create |
| Personnel | PATCH /api/v1/personnel/{personnel} | personnel:EDIT | Update open/current employment configuration |
| Attendance | POST /api/v1/my-attendance/time-in | my-attendance:VIEW | Multipart proof required; server timestamp |
| Attendance | POST /api/v1/my-attendance/time-out | my-attendance:VIEW | Multipart proof required; server timestamp |
| Attendance | GET /api/v1/my-attendance | my-attendance:VIEW | Own records only |
| Attendance | GET /api/v1/attendance | attendance:VIEW | Admin list |
| Attendance | POST /api/v1/attendance/{attendance}/approve | attendance:EDIT | Approve/present |
| Attendance | POST /api/v1/attendance/{attendance}/adjust | attendance:EDIT | Reason required; preserve original |
| Daily comp | GET /api/v1/daily-compensations | daily-compensation:VIEW | Read |
| Daily comp | POST /api/v1/daily-compensations/recalculate | daily-compensation:EDIT | Only unlocked/open dates |
| Commission rules | GET /api/v1/commission-rules | commission-rules:VIEW | Read |
| Commission rules | POST /api/v1/commission-rules | commission-rules:CREATE | Create |
| Commission rules | PATCH /api/v1/commission-rules/{rule} | commission-rules:EDIT | Edit |
| Commission rules | POST /api/v1/commission-rules/{rule}/toggle | commission-rules:TOGGLE | Activate/deactivate |
| Commissions | GET /api/v1/commissions | commissions:VIEW | Read |
| Commissions | POST /api/v1/commissions/reconcile | commissions:EDIT | Idempotent safety reconciliation; guarded |
| Cash advances | GET /api/v1/cash-advances | cash-advances:VIEW | Read |
| Cash advances | POST /api/v1/cash-advances | cash-advances:CREATE | Create/request |
| Cash advances | POST /api/v1/cash-advances/{advance}/approve | cash-advances:APPROVE | Approve/release under policy |
| Cash advances | POST /api/v1/cash-advances/{advance}/transactions | cash-advances:EDIT | Append repayment/adjustment ledger |
| Payroll | GET /api/v1/payroll-runs | payroll:VIEW | Read |
| Payroll | POST /api/v1/payroll-runs/validate-period | payroll:CREATE | Checks Z-reading close and period conflicts |
| Payroll | POST /api/v1/payroll-runs | payroll:CREATE | Generate DRAFT; Idempotency-Key |
| Payroll | POST /api/v1/payroll-runs/{run}/recalculate | payroll:CREATE | Draft only |
| Payroll | GET /api/v1/payroll-runs/{run} | payroll:VIEW | Run + lines + sources |
| Payroll | POST /api/v1/payroll-runs/{run}/approve | payroll:APPROVE | Freeze snapshots; Idempotency-Key |
| Payroll | POST /api/v1/payroll-runs/{run}/mark-paid | payroll:MARK_PAID | Freeze payment history; Idempotency-Key |
| Payroll | GET /api/v1/my-payroll | my-payroll:VIEW | Own linked personnel only |
| Payslips | GET /api/v1/payslips | payslips:VIEW | Authorized list |
| Payslips | GET /api/v1/my-payslips/{payrollLine} | my-payroll:VIEW | Own only |
| Leave | GET /api/v1/leave-requests | leave:VIEW | Read |
| Leave | POST /api/v1/leave-requests | leave:CREATE | Create |
| Leave | POST /api/v1/leave-requests/{leave}/approve | leave:APPROVE | Approve |
| Profit sharing | GET /api/v1/profit-sharing-runs | profit-sharing:VIEW | Read |
| Profit sharing | POST /api/v1/profit-sharing-runs | profit-sharing:CREATE | Generate DRAFT |
| Profit sharing | POST /api/v1/profit-sharing-runs/{run}/recalculate | profit-sharing:EDIT | Draft only |
| Profit sharing | POST /api/v1/profit-sharing-runs/{run}/cancel | profit-sharing:EDIT | Draft only unless special reversal policy |
| Profit sharing | POST /api/v1/profit-sharing-runs/{run}/approve | profit-sharing:APPROVE | Freeze |
| Profit sharing | POST /api/v1/profit-sharing-runs/{run}/mark-paid | profit-sharing:MARK_PAID | Freeze |
| Profit sharing | GET /api/v1/my-profit | my-profit:VIEW | Own partner profile only |
| Admin | GET/POST/PATCH /api/v1/admin/businesses, branches, references, branding | matching Admin permissions | Resource endpoints |
| Admin | GET/POST/PATCH /api/v1/admin/users, roles, role-permissions | users permissions | Super Admin protection; changes audited |
| Audit | GET /api/v1/audit-logs | audit:VIEW | Read-only filters; never direct mutation |

## 13. React page/component map

Exact extensions and routing/data-fetching libraries remain open until `package.json` and the real frontend tree are inspected. The feature boundaries below are the recommended decomposition.


| Feature | Proposed location | Pages/components | Behavior |
| --- | --- | --- | --- |
| App shell | src/app/router.*; src/layouts/AppLayout.*; src/config/navigation.* | RouteGuard/PermissionGuard, Sidebar, TopHeader, PageContainer | Preserve dark sidebar, YODAMEN gold, compact tables, collapsible responsive layout. |
| Shared UI | src/components/ui/* | DataTable, StatusPill, MoneyCell, DateCell, Modal/Dialog, ConfirmDialog, FormField, EmptyState, LoadingState | Replace AdminHTML/ModalLayoutGuard/UXPolish global helpers with reusable components. |
| Auth | src/features/auth/* | LoginPage, ChangeAccessCodeModal, useAuth/usePermissions | No authoritative permission decisions in React. |
| Dashboard | src/features/dashboard/pages/DashboardPage.* | KPI cards/read-model widgets | Implement after source data APIs exist. |
| Customers | src/features/customers/pages/CustomersPage.*, CustomerDetailPage.* | CustomerTable, CustomerForm, CustomerHistoryPanel | Customer history server-side. |
| Bookings | src/features/operations/bookings/pages/BookingsPage.* | BookingTable, BookingFormModal, RebookModal, AdvancePaymentSection | Advance receipt creates Payment only when money collected. |
| Queue | src/features/operations/queue/pages/QueuePage.* | WaitingList, CheckInDialog, WalkInDialog | Walk-in uses same Booking -> Queue flow. |
| Servicing | src/features/operations/servicing/pages/ServicingPage.* | VisitWorkspace, VisitServiceCard/Table, StaffAssignment, ServiceCreditClaim, CompletionDialog | Completion is operational event; no commission creation in UI. |
| Service Balances | src/features/packages/pages/ServiceBalancesPage.* | ServiceBalancesTable, PackagePaymentMonitoringModal | One row per enrollment. |
| Sales Dashboard | src/features/sales/dashboard/pages/SalesDashboardPage.* | TopServicesTable, TopCustomersTable, KPI cards | Count/Amount/% from server read model. |
| Z-Reading | src/features/sales/z-reading/pages/ZReadingPage.* | ZReadingSummary, PaymentBreakdown, CloseDialog, ReopenDialog | Preview and persistent close are separate actions. |
| Transactions | src/features/sales/transactions/pages/TransactionsPage.* | PendingTransactionsTable, SettledTransactionLinesTable, CheckoutModal, PaymentAllocationPanel, OnAccountCollectionModal, TransactionSummaryModal | Tabs Pending / On Account / Paid / Service Credit. |
| Personnel | src/features/hr/personnel/pages/PersonnelPage.* | PersonnelTable, PersonnelForm | Personnel Type, Employment Type, Position are separate controls. |
| My Attendance | src/features/hr/attendance/pages/MyAttendancePage.* | Camera/FileProofInput, TimeInOutCard, AttendanceHistory | Private proof URLs must be signed/authorized. |
| Attendance Admin | src/features/hr/attendance/pages/AttendanceAdminPage.* | ApprovalModal, AdjustmentModal, ProofViewer | Reason required for corrections. |
| Daily Compensation | src/features/hr/compensation/pages/DailyCompensationPage.* | DailyCompTable, RecalculateDialog | Server owns calculations. |
| Commissions | src/features/hr/commissions/pages/CommissionsPage.* | CommissionTable, ReconcileDialog | Read/reconcile only; rule logic server-side. |
| Commission Rules | src/features/hr/commission-rules/pages/CommissionRulesPage.* | RuleTable, RuleForm, PriorityDisplay | Expose hierarchy without duplicating resolver logic. |
| Cash Advances | src/features/hr/cash-advances/pages/CashAdvancesPage.* | AdvanceTable, LedgerModal, ApprovalModal | Employee CA is separate from Sales Advance. |
| Payroll | src/features/hr/payroll/pages/PayrollPage.* | PayrollRunsTable, PayrollRunDetail, GenerateModal, ApprovalModal, MarkPaidModal | DRAFT -> APPROVED -> PAID; locked source trace. |
| My Payroll / Payslips | src/features/hr/payroll/pages/MyPayrollPage.*, PayslipsPage.* | PayslipViewer, PayrollHistory | Strictly linked-personnel scope. |
| Leave & Adjustments | src/features/hr/leave/pages/LeavePage.* | LeaveTable, RequestForm, ApprovalModal | Server status authority. |
| Profit Sharing | src/features/profit-sharing/pages/ProfitSharingPage.*, MyProfitPage.* | RunTable, RunDetail, Generate/Approve/Pay dialogs | Separate lifecycle from Payroll. |
| Admin setup | src/features/admin/business/*; branding/*; references/*; users-roles/*; services/* | Forms/tables/permission matrix | Server-side policies always authoritative. |
| Future Accounting | src/features/accounting/* | Placeholder routes retained until activated | Consume existing Sales/Payment/Accounting events. |
| Future Inventory | src/features/inventory/* | Placeholder routes retained until activated | Consume VisitServiceCompleted and product sale events. |
| Reports | src/features/reports/* | Read-only report pages | No report UI mutates domain tables. |

## 14. Old Apps Script file -> React/Laravel mapping

This appendix covers every file in the attached Apps Script source bundle. "No direct port" means its useful behavior becomes a framework capability, test, migration or canonical service rather than another compatibility file.


| Legacy file | Lines | Purpose | Status | Named Sheets | Target mapping |
| --- | --- | --- | --- | --- | --- |
| .clasp.json | 16 | Apps Script CLI/deployment metadata | Legacy platform metadata; do not port | None directly | None; Git/Laravel/Vite deployment configuration replaces it |
| Admin.js | 3252 | Business, branch, branding, reference, user/role administration | Current core Admin backend | DB_Users, REF_Branches, REF_Branding, REF_Business, REF_General, REF_Permissions, REF_Roles, SYS_Config | backend/app/Domain/Admin/* + Api/V1/Admin controllers + Admin models/policies |
| AdminHTML.html | 5024 | Admin UI, modals, shared admin UI helpers/toasts | Current UI; also a dependency hub for other HTML | None directly | frontend/src/features/admin/* + frontend/src/components/ui/* |
| App.html | 2304 | Navigation, page router, app bootstrap, permission-aware menu | Current shell/router | None directly | frontend/src/app/router.* + frontend/src/layouts/AppLayout.* + navigation config |
| Audit.js | 10 | Append audit rows to DB_Audit_Log | Current but too thin for target requirements | DB_Audit_Log | backend/app/Domain/Audit/AuditService.php + AuditLog model/migration |
| Auth.js | 727 | Username/access-code login, sessions, permission enforcement | Current core security | DB_Users, REF_Permissions, REF_Roles | Laravel authentication + AuthController + AuthorizationService + Policies/Gates |
| BookingDiagnostic.js | 322 | Booking/visit diagnostic utility | Diagnostic only | DB_Bookings, DB_VisitServices, DB_Visits | PHPUnit feature tests / development-only Artisan diagnostics |
| BookingDiagnostic2.js | 1032 | Expanded booking/visit diagnostic utility | Diagnostic only | DB_Bookings, DB_VisitServices, DB_Visits | PHPUnit feature tests / development-only Artisan diagnostics |
| BookingDiagnostic2HTML.html | 114 | UI for booking diagnostics | Diagnostic only; no production parity requirement | None directly | No production page; optional local/dev diagnostic tooling |
| BookingVisits.js | 4469 | Booking, check-in, queue/visit, servicing lifecycle and staff assignment | Current core Operations backend | DB_BookingRetailItems, DB_BookingServices, DB_Bookings, DB_Customers, DB_Employees, DB_Queue, DB_QueueRetailItems, DB_QueueServices, DB_ServiceCreditUsage, DB_ServiceCredits, DB_ServicePrices, DB_Services, DB_VisitRetailItems, DB_VisitServices, DB_Visits | backend/app/Domain/Operations/* + Booking/Queue/Visit/VisitService controllers |
| BookingVisitsHTML.html | 1570 | Bookings/queue Operations UI | Current UI | None directly | frontend/src/features/operations/bookings/* + queue/* |
| BookingVisitsSetup.js | 617 | Creates/upgrades booking/visit/credit sheets and permissions | Setup/migration evidence only | DB_BookingRetailItems, DB_BookingServices, DB_Bookings, DB_ServiceCreditUsage, DB_ServiceCredits, DB_VisitRetailItems, DB_VisitServices, DB_Visits, REF_General, REF_Permissions | Laravel database migrations + seeders |
| Code.js | 27 | Apps Script web entrypoint, include helper, bootstrap | Legacy platform shell | None directly | Laravel API bootstrap + React entrypoint; no direct port |
| CommissionMultiStaffFix.js | 849 | Commission reconciliation and multi-staff repair logic | Current fix/reconciliation evidence; runtime repair should disappear | DB_Bookings, DB_CommissionEarnings, DB_Customers, DB_Employees, DB_Sales, DB_SalesItems, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicePrices, DB_VisitServices, DB_Visits, REF_CommissionRules | CommissionService/ReconcileCommissions action + import checker + tests |
| Config.js | 32 | Reads/caches SYS_Config and business info | Current shared config | REF_Business, SYS_Config | SystemSetting/Business models + config service/cache |
| Customer.js | 1662 | Customer CRUD/search plus selected customer financial/activity reads | Current core | DB_Customers, DB_Sales, DB_SalesPayments, DB_Visits, REF_General, REF_Permissions, SYS_Config | CustomerController + CustomerService/queries + CustomerPolicy |
| CustomerActivity.js | 225 | Builds customer visit/sales/service-credit activity | Current read model | DB_Sales, DB_ServiceCredits, DB_VisitRetailItems, DB_VisitServices, DB_Visits | CustomerHistoryReadModel / API resource |
| CustomerHTML.html | 2375 | Customer management UI | Current UI | None directly | frontend/src/features/customers/* |
| Database.js | 71 | Generic sheet open/full-table scan/append helpers | Legacy infrastructure; explicitly replace | None directly | Eloquent/query builder/repositories as needed; no generic table-name API |
| DevelopmentReset.js | 1133 | Development-only cascade/reset and relationship discovery | Development/test only | DB_AccountingMapping, DB_AccountingMappings, DB_ActivityLog, DB_ActivityLogs, DB_AuditLog, DB_AuditLogs, DB_BankAccounts, DB_BookingRetailItems, DB_BookingServices, DB_Bookings, DB_Branch, DB_Branches, DB_Branding, DB_Business, DB_Businesses, DB_CashAccounts, DB_ChartOfAccounts, DB_CommissionEarnings, DB_Customers, DB_Employees, DB_HREodSummary, DB_InventoryItems, DB_InventoryMaster, DB_OperationalAuditLog, DB_OperationalAuditLogs, DB_PackageDefinitions, DB_PaymentAllocations, DB_Permissions, DB_ProductCatalog, DB_ProductMaster, DB_Products, DB_Queue, DB_QueueRetailItems, DB_QueueServices, DB_Roles, DB_Sales, DB_SalesItems, DB_SalesPaymentAllocations, DB_SalesPayments, DB_SalesRebookingLog, DB_ServiceCompletionEvents, DB_ServiceCosting, DB_ServiceCosts, DB_ServiceCreditLedger, DB_ServiceCreditUsage, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicePackages, DB_ServicePriceHistory, DB_ServicePrices, DB_Services, DB_ServicingPaymentAllocations, DB_Settings, DB_Suppliers, DB_TransactionLog, DB_TransactionLogs, DB_TransactionPaymentAllocations, DB_Users, DB_Vendors, DB_VisitRetailItems, DB_VisitServices, DB_Visits, SYS_AuditLog, SYS_AuditLogs, SYS_Branding, SYS_Config, SYS_ID_Sequences, SYS_Sequences, SYS_Settings | Factories/seeders + development-only Artisan command guarded by environment |
| HRHTML.html | 3689 | Personnel, attendance, comp, commissions, cash advances, payroll, payslips, leave UI | Current large HR UI | None directly | frontend/src/features/hr/* split by module |
| HRPayroll.js | 3720 | Personnel, compensation, commission, HR adjustments, cash advances, payroll and HR EOD logic | Current core HR backend | DB_Attendance, DB_Bookings, DB_CashAdvanceLedger, DB_CashAdvancePayrollAllocations, DB_CashAdvances, DB_CommissionEarnings, DB_Customers, DB_DailyCompensation, DB_Employees, DB_HRAdjustments, DB_HREodSummary, DB_PayrollLines, DB_PayrollRuns, DB_Sales, DB_SalesItems, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicePrices, DB_Services, DB_Users, DB_VisitServices, DB_Visits, REF_Branches, REF_CommissionRules, REF_General, REF_Permissions, REF_Roles | backend/app/Domain/HR/* + corresponding models/controllers/services |
| HRPayrollV9Diagnostics.js | 104 | HR/payroll diagnostics | Diagnostic only | None directly | PHPUnit tests + local/dev Artisan diagnostics |
| Index.html | 517 | Apps Script SPA document and HTML include order | Legacy shell; reveals load-order dependencies | None directly | frontend/index.html + React main entry; no runtime includes |
| ModalLayoutGuard.html | 374 | Global modal layout patch | Compatibility/UI patch | None directly | Reusable Modal/Dialog component and scoped CSS |
| OperationsPaymentFastBridge.js | 117 | Fast payment-status bridge used by Operations UI | Compatibility/read bridge | None directly | Booking/Visit payment-summary API/read model; no bridge file |
| OperationsPaymentStatus.js | 681 | Computes booking payment status from Sales/Payments | Current read logic | DB_Bookings, DB_Sales, DB_SalesPayments | OperationsPaymentStatusReadModel derived from payments/applications |
| OperationsPerformance.js | 1088 | Optimized Operations list/bootstrap/read endpoints | Current read backend | DB_BookingRetailItems, DB_BookingServices, DB_Bookings, DB_Customers, DB_Queue, DB_QueueServices, DB_VisitServices, DB_Visits | Operations query/read-model services + endpoints |
| OperationsPerformanceHTML.html | 5803 | Large V26-V33 Operations/Sales UI patches and runtime overrides | Current behavior evidence, not an architecture to port | None directly | Split React feature components/hooks/API clients; no compatibility monolith |
| PartnerProfitProfileV2.js | 252 | Partner profile/profit-sharing personnel extensions | Compatibility layer; HRPayroll already carries related fields | DB_Employees, DB_Users, REF_Branches, REF_General | Personnel domain fields/services + MyProfit read model |
| PaymentDebug.js | 467 | Payment debugging utility | Diagnostic only | DB_Bookings, DB_Sales, DB_SalesPayments, DB_Visits | Financial integration tests/import diagnostics |
| PaymentTraceDiagnostic.js | 967 | Traces payment/sale/visit relationships | Diagnostic only | DB_Bookings, DB_Sales, DB_SalesPayments, DB_VisitServices, DB_Visits | Payment trace query for tests/admin diagnostics, not normal runtime |
| ProfitSharing.js | 562 | Profit-sharing runs/lines, approval/payment and My Profit data | Current/recovered implementation; deployment status needs verification | DB_CommissionEarnings, DB_Employees, DB_ProfitSharingLines, DB_ProfitSharingRuns, REF_Permissions, REF_Roles | backend/app/Domain/ProfitSharing/* + controllers/models/policies |
| ProfitSharingHTML.html | 109 | Profit Sharing and My Profit UI | Current/recovered UI | None directly | frontend/src/features/profit-sharing/* |
| PublicBranding2026.js | 309 | Public/bootstrap branding access | Compatibility/public bootstrap helper | None directly | Public/bootstrap branding endpoint + frontend theme provider |
| QueueVisitSetup.js | 328 | Creates/upgrades queue and visit sheets | Setup/migration evidence only | DB_Bookings, DB_Queue, DB_QueueRetailItems, DB_QueueServices, DB_VisitRetailItems, DB_VisitServices, DB_Visits | Laravel migrations |
| ReceptionWalkInPermission2026.js | 258 | Patches Reception walk-in permission behavior | Current permission fix; do not port as patch | REF_Permissions, REF_Roles | Booking/Queue Policies + permission seeder + feature test |
| References.js | 276 | Reference list and branding readers/caches | Current shared reference layer | REF_Branding, REF_General | ReferenceGroup/ReferenceValue service + endpoints/cache |
| RolePermissions2026.js | 594 | Role/action permission matrix management | Current permission backend | REF_Permissions, REF_Roles | RolePermissionService + policies + admin permission endpoints |
| Sales.js | 1195 | Sales creation, payments, rebooking, checkout, package and transaction reads | Current core Sales backend with legacy sequential allocation fallback | DB_Bookings, DB_Customers, DB_Queue, DB_Sales, DB_SalesItems, DB_SalesPayments, DB_SalesRebookingLog, DB_ServiceCreditLedger, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_VisitRetailItems, DB_VisitServices, DB_Visits | backend/app/Domain/Sales/* + Sales/Payment models/controllers |
| SalesAccessBridge2026.js | 35 | Permission/access compatibility for Sales | Compatibility bridge | None directly | Policies/Gates + seeded permissions; no bridge |
| SalesDashboard.js | 571 | Sales dashboard metrics/read model | Current read backend | DB_Customers, DB_Sales, DB_SalesItems, DB_SalesPayments, DB_Visits | SalesDashboardReadModel + endpoint |
| SalesDashboardRankings2026.js | 374 | Top-service/customer dashboard ranking patch | Current dashboard enhancement | DB_Customers, DB_Sales, DB_SalesItems, DB_SalesPayments | SalesDashboardReadModel/query + React dashboard widgets |
| SalesHTML.html | 1249 | Transactions/checkout/payment UI | Current UI | None directly | frontend/src/features/sales/transactions/* |
| SalesPayrollBridge.js | 1264 | Coordinates finalized Sales with allocations, package reconciliation and commissions/payroll | Current critical bridge; outcomes must become canonical orchestration | DB_Sales | FinalizeCheckout/CollectOnAccount domain actions + Commission/Package services |
| SalesServiceLineSettlement2026.js | 2640 | Parent pending vs line-level settlement, V33 integrity guard/duplicate repair | Current critical read/integrity layer; V33 repair not confirmed deployed | DB_Customers, DB_Sales, DB_SalesDuplicateArchive, DB_SalesItems, DB_SalesPayments, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicingPaymentAllocations, DB_VisitServices | SettlementLineReadModel + DB constraints + import repair report/tests |
| SalesSetup.js | 338 | Creates/upgrades Sales/SalesItems/Payments/Rebooking sheets and permissions | Setup/migration evidence only | DB_Sales, DB_SalesItems, DB_SalesPayments, DB_SalesRebookingLog, REF_General, REF_Permissions, REF_Roles | Laravel migrations + seeders |
| ServiceBalanceFinancial2026.js | 551 | Per-package financial monitoring and applied-payment read logic | Current critical read model | DB_Customers, DB_SalesPayments, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicingPaymentAllocations | ServiceBalanceReadModel + PackageEnrollment financial endpoint |
| ServiceCredit.js | 1358 | Package enrollment/service-credit entitlement and usage workflows | Current critical package/credit backend; contains cumulative stacking rule | DB_BookingServices, DB_Queue, DB_QueueRetailItems, DB_QueueServices, DB_ServiceCompletionEvents, DB_ServiceCreditLedger, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_VisitServices | backend/app/Domain/Packages/* |
| ServiceCreditSetup.js | 453 | Creates/upgrades package/credit/completion-event sheets | Setup/migration evidence only | DB_BookingServices, DB_QueueServices, DB_ServiceCompletionEvents, DB_ServiceCreditLedger, DB_ServiceCreditUsage, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_VisitServices | Laravel migrations |
| Services.js | 3001 | Services/pricing CRUD and price history behavior | Current core master-data backend | DB_ServicePrices, DB_Services | Service/ServicePrice controllers/services/models |
| ServicesHTML.html | 2338 | Services & Pricing UI | Current UI | None directly | frontend/src/features/admin/services/* |
| ServicesSetup.js | 900 | Creates/upgrades services/prices and related permissions | Setup/migration evidence only | DB_ServicePrices, DB_Services, REF_General, REF_Permissions | Laravel migrations + seeders |
| ServicingPaymentAllocation.js | 3655 | V3-V5 service-line payment allocation and Service Credit package-balance application | Current critical financial allocation logic; canonical behavior source | DB_Sales, DB_SalesItems, DB_SalesPayments, DB_ServiceCredits, DB_ServicePackageEnrollments, DB_ServicingPaymentAllocations, DB_VisitServices | PaymentApplicationService + AllocationValidator + Checkout UI allocation components |
| Setup.js | 588 | Initial core sheets, config, business, branch, roles, permissions, users, audit | Setup/migration evidence only | DB_Audit_Log, DB_Users, REF_Branches, REF_Branding, REF_Business, REF_General, REF_Permissions, REF_Roles, SYS_Config | Foundation migrations + seeders |
| Styles.html | 7156 | Global CSS/theme | Current UI styling evidence | None directly | frontend/src/styles/tokens.css + layout/component styles |
| TestCustomer2026.js | 338 | Test-customer helpers | Development/test only | DB_Customers, DB_Users | Factories/seeders/test fixtures |
| UXPolish.html | 6808 | Large UI/branding/behavior patch layer with runtime overrides/observers | Compatibility layer; do not port literally | None directly | React components, theme/layout CSS, focused hooks |
| UserAccessBranding2026.js | 909 | User access/bootstrap branding compatibility | Compatibility bridge | DB_Users, REF_Roles | Auth / bootstrap resource endpoint |
| VisitWorkspaceHTML.html | 1323 | Servicing visit workspace UI | Current UI | None directly | frontend/src/features/operations/servicing/* |
| YodamenAttendanceV19.gs.js | 2852 | Self-attendance, proof upload, admin approval/adjustment | Current attendance source; header/version naming is newer than filename | DB_Attendance, DB_DailyCompensation, DB_Employees, DB_Users, REF_Permissions, REF_Roles | backend/app/Domain/HR/Attendance/* + private file storage + React attendance pages |
| YodamenHRServicingV6Bridge.js | 558 | HR/Servicing compatibility bridge and commission visibility/repair helpers | Compatibility layer; do not port as bridge | DB_Bookings, DB_CommissionEarnings, DB_Customers, DB_Employees, DB_Sales, DB_SalesItems, DB_VisitServices, DB_Visits | VisitService validation + Commission read/reconcile services |
| ZReadingHTML.html | 275 | Z-Reading UI | Current UI | None directly | frontend/src/features/sales/z-reading/* |
| ZReadings.js | 401 | Read-only daily Z-Reading metrics and audit image/email helper | Current read implementation; persistent close is incomplete here | DB_Customers, DB_Sales, DB_SalesPayments, DB_Users, DB_Visits | ZReadingService + ZReadingController + persistent z_readings close table |
| appsscript.json | 10 | Apps Script manifest/timezone/deployment metadata | Legacy platform metadata | None directly | Laravel/Vite/environment config; timezone setting retained |

## 15. Authentication, roles and permissions architecture

| Concern | Blueprint decision |
| --- | --- |
| Authentication mechanism | Use the installed Laravel Sanctum v4.3.3 as the first-party SPA authentication baseline. Configure the actual Vite origin as stateful and prefer session/cookie authentication rather than storing bearer tokens in browser localStorage. |
| Login identity | Preserve username + Access Code style if desired for user familiarity. Store the credential with Laravel Hash, not the legacy SHA-256 format. |
| Legacy credentials | Recommended migration is forced credential reset. Import username, status, role and personnel link; mark force_reset. A transitional legacy verifier is possible only with explicit approval. |
| Authorization source | roles + permissions + role_permissions in MySQL. Policies/Gates/middleware protect every server mutation and sensitive read. |
| React behavior | Navigation/actions hide or disable based on permission data returned by /auth/me or bootstrap. This remains UX only. |
| Personal scope | My Payroll, My Profit and My Attendance resolve Personnel from the authenticated User server-side. Client-supplied personnel IDs never expand scope. |
| Super Admin | Protected system role. Core permissions cannot be removed through normal role editing. Permission changes are audited. |
| Rate limiting/lockout | Retain failed-login protection through Laravel RateLimiter plus account status/locked_until when needed. |
| Branch/business scope | Every request resolves authorized business/branch scope from session/user membership, then policies constrain records. |

### 15.1 Permission model

Seed one canonical permission catalog with module + action pairs. The source currently exercises actions such as VIEW, CREATE, EDIT, COMPLETE, APPROVE, MARK_PAID, TOGGLE, RESET_CODE, MANAGE_PERMISSIONS, MANAGE_BRANCHES and CHANGE_PRICE. Laravel Policies/Gates resolve these against the authenticated user's role.

Reception Walk-In needs a composite authorization check. Queue CREATE permits the operational walk-in flow, while any Advance collection also requires the appropriate Booking/payment authority.

Permission changes, role changes and account resets create audit records. The protected Super Admin role cannot lose required core access through the ordinary matrix editor.


## 16. Transaction and audit architecture

### 16.1 Atomic boundaries


| Use case | Transaction boundary |
| --- | --- |
| Create Booking with Advance | One DB transaction creates Booking/lines and, only when money was actually collected, an ADVANCE Payment. No commission and no Sale finalization. |
| Check-in / Walk-in | Walk-in action creates the same Booking aggregate first. Queue/Visit records then follow the normal lifecycle. |
| Complete Visit Service | Lock VisitService, validate transition, mark COMPLETED, append completion event, consume Service Credit session when applicable, prepare inventory-consumption event. No commission. |
| Create Sale from Completed Visit | Lock Visit, find/create Sale by unique visit_id, create each SaleItem from deterministic source_key. Concurrent second call returns the existing aggregate/no-op. |
| Finalize Checkout | Lock Sale and relevant applications/package obligations. Validate allocations and target capacities. Create current CHECKOUT Payment when amount > 0, require method, persist applications, update finalized lifecycle, activate/reconcile package, create eligible commissions, write audit, commit atomically. |
| Confirm fully covered Sale | Same finalization service with zero new Payment; existing valid receipts/applications must cover the charge. |
| On Account Collection | Lock Sale/targets, create COLLECTION Payment, persist manual applications, derive new line/parent balances, audit. Existing commission is not recreated. |
| Service Credit + package payment | Current redemption remains zero-charge SERVICE_CREDIT. Received cash is Payment ledger activity and application target is the originating PackageEnrollment/source Sale obligation. |
| Commission invalidation | Append/reverse OPEN earning when source becomes invalid. Draft payroll can recalc. Approved/Paid payroll receives a future adjustment instead of historical rewrite. |
| Refund/correction | Original Payment/Application remains. Refund/reversal records restore receivable/package effects and trigger downstream adjustment logic. |
| Payroll | Generate DRAFT locks eligible sources; Approve freezes snapshots; Mark Paid freezes payment history. All three actions use idempotency and transaction locks. |
| Profit Sharing | Generate/Recalculate DRAFT, Approve freeze, Mark Paid freeze. Partner percentage snapshot uses Period End effective rate. |

### 16.2 Audit model

Every important domain mutation writes `audit_logs` inside the same database transaction when the audit is part of the required business record. Fields include actor, action, module/entity, entity ID/code, before/after JSON where appropriate, reason, correlation/request ID, idempotency key, IP/user-agent metadata where permitted, and timestamp.

Reason becomes mandatory for attendance correction, cancellation after progress, commission reversal, refund/financial correction, inventory adjustment, exceptional payroll/profit-sharing correction and permission changes.

Application logs remain useful for exceptions and performance diagnostics. Business audit history lives in SQL and is queryable by authorized users.


## 17. Scheduled process and automation architecture

| Process | Target architecture |
| --- | --- |
| Laravel Scheduler | Run schedule:run through the deployment's normal cron. Application timezone/business-date calculations use Asia/Manila unless multi-timezone support is introduced. |
| Queue worker | Use queue workers for non-critical after-commit work such as notification/email delivery, report generation, image processing and read-model refresh. Failed jobs remain retryable and idempotent. |
| Service-credit expiry | A scheduled idempotent job can update entitlement status when expiry rules require it. Session/payment history stays untouched. |
| Integrity monitoring | A scheduled read-only integrity audit can detect impossible conditions such as duplicate source keys, allocation mismatches or orphaned references. It reports issues rather than silently repairing financial history. |
| Financial finalization | No background job auto-finalizes Sales, Payroll, Profit Sharing, refunds or permission changes. These remain explicit business actions. |
| Z-Reading | Day close is an explicit action using server time. Scheduler may remind/flag unclosed dates, but should not fabricate a close. |
| Inventory/report projections | After future activation, asynchronous listeners may update caches/projections after the core transaction commits. Ledger truth stays in SQL source tables. |
| Housekeeping | Framework cache/session/job cleanup follows Laravel-supported mechanisms. Development reset remains an environment-guarded command, never a production route. |

## 18. Historical data migration approach

| Step | Approach |
| --- | --- |
| 1. Source freeze and manifest | Export each Google Sheet with exact headers, row count, legacy IDs, status values and file/proof references. Record export timestamp and Apps Script deployment/version. |
| 2. Raw staging | Load CSV/JSON rows as strings into import staging tables or a separate staging schema. Preserve original row number, source sheet and raw JSON for traceability. |
| 3. Master-data mapping | Import business, branches, references, roles/actions, users, customers, services/prices, personnel and commission rules. Create legacy_id -> new_id mappings. |
| 4. Operations import | Bookings, rebookings, queue entries, visits, visit service/retail lines. Resolve copied staff/service names to stable FKs while preserving snapshots. |
| 5. Package/credit reconstruction | Import Package Enrollments first, then entitlement ledger/usage. Decompose cumulative ServiceCredits to enrollments using Origin Sale/Item, Package Enrollment IDs and ledger evidence. Unresolved rows become exceptions. |
| 6. Sales/payment import | Import Sales/SaleItems, received Payments and active financial Applications. Apply one-Sale-per-Visit and source-key checks. Pending race duplicates can be archived only under documented rules. |
| 7. HR import | Attendance/proofs, daily compensation, commission earnings, HR adjustments, employee cash advances/ledger, payroll runs/lines/sources. Approved/Paid snapshots are copied as historical truth. |
| 8. Profit Sharing import | Import runs/lines with period-end percentage snapshots and statuses. No recalculation of Approved/Paid history. |
| 9. File migration | Fetch attendance/proof files from Google Drive with source IDs, hash/size/MIME metadata, then place them in private Laravel storage. Keep legacy Drive IDs in migration metadata. |
| 10. Reconciliation | Compare customer/booking/completed-visit counts, parent Sales, gross, payments by type/method/date, On Account, package paid/balance, sessions, commissions by employee, payroll and profit-sharing totals. |
| 11. Exception control | Write every excluded/merged/ambiguous legacy row to migration_exceptions with source sheet/row, reason, proposed action and reviewer status. Finalized conflicts are never auto-merged. |
| 12. Repeatable rehearsal | Run importer from a clean yodamen_dev repeatedly. Idempotent results and identical reconciliation totals are required before cutover. |
| 13. Cutover | Freeze legacy writes, capture a final/delta export, rerun controlled import, reconcile, switch users, retain Apps Script/Sheets read-only for audit period. |

### 18.1 Legacy import control tables

Retain a small migration metadata layer during and after cutover:

- `migration_runs`: source export/version, start/end, status, checksum and counts.
- `legacy_source_maps`: source sheet + legacy code/row -> target table/id/code.
- `migration_exceptions`: ambiguous/orphan/duplicate/finalized-conflict rows and review resolution.
- `migration_reconciliation_results`: metric, source total, target total, difference, run ID.

These tables support traceability without turning V33 duplicate-repair structures into normal production transaction logic.


## 19. Known migration risks

| Severity | Risk | Impact | Control |
| --- | --- | --- | --- |
| High | Target environment defaults do not yet match YODAMEN | `.env.example` points to SQLite, Laravel app timezone is UTC, and Sanctum stateful defaults do not explicitly include Vite `:5173`. Left unchanged, authentication/date/database behavior could diverge immediately. | Resolve MySQL, Asia/Manila business-time, SPA origin/session and environment conventions in Phase 1 before domain modules. |
| Critical | Legacy duplicate Sales/SaleItems | The source documents and V33 layer show race-created pending duplicates. Importing them as-is would violate one-Sale-per-Visit and inflate checkout lines. | Pre-import integrity scan; auto-resolve only obvious pending duplicates; finalized conflicts go to migration exceptions/manual review. |
| Critical | Financial aggregates may diverge from allocation ledger | DB_Sales and Package Enrollment cache paid/balance totals while later V5 allocations add direct/external applied amounts. | Treat valid received Payments + active Payment Applications as authoritative; reconcile every imported aggregate and report differences. |
| High | Service-credit cumulative stacking conflicts with enrollment-level target | Current ServiceCredit source merges repeat purchases into one customer+service credit balance, while approved handoff requires two purchases to remain separate enrollments. | Import purchase-level enrollment/ledger lineage; allocate usage to a specific enrollment. Exception rows require manual resolution when lineage is insufficient. |
| High | Pure Service Credit finalization semantics are ambiguous | Current Sales source bypasses checkout for pure zero-charge Service Credit sales and effectively treats them as finalized, while approved rules preserve financial finalization as the commission trigger. | Adopt one explicit server-side finalization event for zero-charge redemption; decide whether it is automatic after completed Visit or requires a staff confirmation action. |
| High | Z-Reading close implementation is split | Current ZReadings.js produces a read-only report. Payroll code contains a separate HR EOD close hook and compatibility fallback. | Create persistent z_readings close records and make payroll validate those records directly. Remove header-scanning fallback. |
| High | Legacy access-code hash is not a Laravel password hash | Apps Script uses SHA-256(salt\|accessCode). Directly copying it into Laravel's password column will not authenticate with Laravel Hash. | Recommended cutover: import accounts/roles, force credential reset on first Laravel login. A temporary legacy verifier would require separate approval. |
| High | Spreadsheet rows have no database-enforced FKs | Orphaned IDs, duplicated codes, mismatched copied names and stale status fields can exist even if UI normally prevented them. | Stage raw data first, validate all foreign-key relationships, then load normalized tables. |
| High | Load-order and monkey-patch behavior can hide the effective UI implementation | Index includes large files where later UX/Operations patches override earlier global functions. | Use source behavior as evidence and handoff rules as precedence; React gets one canonical component/action per use case. |
| High | Approved payroll/profit-sharing history must remain frozen | Legacy corrections or migration transformations could accidentally recompute historical approved/paid values using current rates or partner percentages. | Import historical snapshots exactly; reconcile totals; future corrections use explicit adjustment/reversal. |
| Medium | Attendance proofs currently depend on Google Drive IDs | Private files may be inaccessible or publicly misconfigured after migration. | Export files with source metadata, store privately through Laravel storage, and authorize every proof download. |
| Medium | Timezone/date interpretation | Google Sheets Date values and browser times may produce date shifts during conversion. | Use Asia/Manila as business timezone; store instants consistently (prefer UTC) and explicit business_date columns for local-day rules. |
| Medium | Human-readable ID generation currently uses script scans/sequences | Concurrent SQL requests could collide if legacy generation is copied. | Use a transaction-safe code_sequences table or database-backed generator with unique indexes. |
| Medium | Historical service price/commission rule reconstruction | Some source rows carry snapshots, while master pricing/rules can change over time. | Import snapshots on financial/commission records; never recompute finalized history from current master data. |
| Medium | Retail master is less mature than service master | Existing booking/visit/sale retail lines reference item IDs without a clearly authoritative product/inventory subsystem in the provided source. | Create a minimal product master for parity; activate stock accounting later. |
| Medium | Future modules appear in navigation before their domain tables exist | Users can see placeholders for accounting, inventory and reports. | Keep recognizable navigation with disabled/placeholder state until each module satisfies its definition of done. |

## 20. Questions, contradictions and source-resolution decisions

| # | Finding / question | Source evidence | Resolution / required decision |
| --- | --- | --- | --- |
| 1 | React/Laravel repository availability | Repository ZIP is now present and inspected. | **Resolved.** The target is a clean skeleton with no YODAMEN modules yet. |
| 2 | Pure Service Credit finalization | Base Sales code can treat pure zero-charge Service Credit visits as not requiring checkout, while the approved commission rule requires financial confirmation before commission posting. | Preserve a server-side `SaleFinalized` event even when collected amount is zero. UX may use a confirmation action instead of a payment form. Exact interaction can be finalized in the Sales phase. |
| 3 | Service Credit stacking | Current `ServiceCredit.js` explicitly aggregates repeat customer + service package purchases into one cumulative Service Credit balance. The handoff requires one Service Balance row per Package Enrollment. | **Resolved by precedence:** target is enrollment-specific. Legacy stacked data requires import allocation/exception handling. |
| 4 | Z-Reading close | Current `ZReadings.js` describes a daily read-only Z-Reading, while `HRPayroll.js` contains the payroll-facing EOD close marker/hook. | **Resolved architecturally:** target Z-Reading persists a first-class close record; Payroll checks it directly. |
| 5 | Payment method enforcement | Base Sales checkout can write a positive CHECKOUT payment with a blank method; V5 allocation logic rejects blank method. Handoff requires method when collected amount > 0. | **Resolved by approved rule:** Laravel rejects positive receipts without a valid payment method. |
| 6 | Credential migration | Apps Script users authenticate through salted SHA-256 Access Code hashes. Laravel's current `users` table uses a normal `password` hash. | Recommended: preserve username/Access Code UX, store new credentials with Laravel Hash, import users with forced credential reset. Exact preservation of existing plaintext Access Codes is impossible because source stores hashes only. |
| 7 | Profit Sharing deployment certainty | Source contains recovered Profit Sharing/My Profit logic; handoff says deployed status requires verification. | Preserve approved rules. Historical export later determines actual data presence. |
| 8 | Historical Google Sheets data | Not supplied yet. | Row counts, actual duplicate extent, orphaned relationships, stacked credit history and monetary reconciliation remain deferred to controlled import rehearsal. |
| 9 | Frontend libraries | Repository confirms Axios + React Router are installed; no query/state framework is installed. | **Resolved:** reuse Axios/router. Do not add Redux/TanStack/Zustand by default. |
| 10 | MySQL environment mismatch | Target is `yodamen_dev`; `.env.example` currently defaults to SQLite. | Phase 1 changes the example/dev contract to MySQL while never embedding real credentials. |
| 11 | Timezone strategy | Repository app timezone is UTC; approved YODAMEN business timezone is Asia/Manila. | Phase 1 must define business-date handling before Booking, Z-Reading, Attendance or Payroll logic. Recommended: explicit Asia/Manila application/business timezone with disciplined UTC-capable timestamp storage where practical. |
| 12 | Sanctum SPA origin | Sanctum installed, but default stateful domains omit normal Vite `:5173`. | Phase 1 configures stateful SPA auth for the actual frontend origin and validates CSRF/session behavior. |
| 13 | Git branch state | ZIP contains no `.git`. | Before first code checkpoint, user/development agent verifies active branch and ensures work occurs on the active development branch, with `stable/approved` reserved for approved merges. |
| 14 | Laravel vs separate React build | Laravel's own default Vite/Tailwind resources remain while YODAMEN has a separate `frontend/` Vite project. | Keep the architectures separate initially. Do not delete Laravel's default assets until deployment strategy makes them unnecessary. |
## 21. Recommended module-by-module migration sequence

Repository inspection is complete. Phase 0 now consists only of approving this blueprint and, after approval, writing the required repository continuity documents before domain implementation begins.

| Phase | Scope | Deliverable / exit gate |
| --- | --- | --- |
| 0 | Blueprint/source/repository audit | **Completed for supplied sources.** Approve this blueprint; verify Git branch in working clone; then create the required migration continuity docs. | No production behavior change. |
| 1 | Foundation and security | MySQL dev contract, business timezone, Business/Branch/Settings, users, roles/actions, Sanctum SPA auth, policies, audit, code generator, idempotency framework, API response/error conventions, React authenticated shell. | Auth/authorization/audit tests pass; Super Admin protected; React login/bootstrap works. |
| 1B | Operational master dependencies | References/branding, Services/Pricing, minimal Products, Personnel identity/position/staff capability fields. | Booking/Servicing can use valid relational FKs. Payroll logic remains later. |
| 2 | Customers and Operations | Customers, bookings, walk-ins, queue, visits, service assignment/start/complete/cancel, rebooking. | Booking -> Queue -> Visit waterfall and same-flow walk-ins pass. |
| 3 | Sales core | One Sale per completed Visit, Sale Items, Payment ledger, Advance linking, Pending parent Transactions. | Unique constraints and concurrent Sale-creation test pass. |
| 4 | Payment Applications and finalized settlement | Advance allocation, checkout allocation, positive-payment validation, atomic finalization, Paid/On Account/Service Credit line projection, later collections, duplicate-submit idempotency. | Waterfall cases A-D, H, L, M pass. |
| 5 | Packages and Service Credits | Package enrollment, activation, enrollment-specific entitlement, redemption, Service Credit + package-balance payment redirection, Service Balances monitoring. | Waterfall cases E-G and package reconciliation pass. |
| 6 | Commission engine | Priority resolver, service-line earnings, multi-staff, package-session basis, reversal logic, idempotency. | Cases I and K pass; package purchase commission remains zero. |
| 7 | Attendance, compensation, Z-close and Payroll | Self-attendance/private proof, approval, daily compensation, cash advances, leave/adjustments, persistent Z-reading close, payroll DRAFT/APPROVED/PAID, payslips. | Case O and attendance/compensation tests pass. |
| 8 | Profit Sharing | Partner configuration snapshots, run lifecycle, My Profit. | Case P passes; Payroll remains independent. |
| 9 | Admin parity and UI polish | Complete admin pages, permission matrix, branding, compact responsive UI, placeholder navigation. | Parity workflows/design approved. |
| 10 | Historical import rehearsal | Raw staging importer, mappings, duplicate/exception reports, attendance file migration, full reconciliation in `yodamen_dev`. | Repeatable/idempotent reconciliation report approved. |
| 11 | Parallel parity validation and cutover preparation | Controlled old/new cases; compare statuses, money, sessions, commissions, payroll and audit. | Core migration cutover gate. |
| 12 | Future Accounting | AR, Collections, Expenses, Cash/Bank, Daily Closing, journals/FS when separately approved. | Uses the core event/payment model. |
| 13 | Future Inventory | Stock ledger, service recipes/consumption, counts/adjustments/reorder. | Uses VisitServiceCompleted and product finalization events. |
| 14 | Reports | Read-only Sales/Customer/Service/Staff/HR/Accounting/Inventory projections. | No mutation endpoints. |
| 15 | Production cutover | Freeze legacy writes, final delta import, reconcile, switch authoritative system, retain legacy read-only/archive access. | Only after critical parity/import gates pass. |
## 22. Transaction waterfall parity suite

The target should encode the handoff's waterfall cases as automated feature/integration tests. The minimum suite covers:

1. Standard completed service, fully paid at checkout.
2. Advance fully covering a service.
3. Multi-service booking with Advance + partial Checkout + On Account remainder.
4. Later partial and final On Account collections without duplicate commission.
5. New package purchase with zero package-purchase service commission.
6. Service Credit redemption with no cash.
7. Service Credit redemption with cash applied to the old package/source balance.
8. Mixed booking containing Paid, On Account, Service Credit and optional package purchase lines.
9. Multi-staff booking with one commission evaluation per completed service line.
10. Cancellation before service.
11. Invalidation of an OPEN commission and future adjustment behavior once payroll is frozen.
12. Concurrent Sale creation attempts for one Visit.
13. Duplicate HTTP checkout submission.
14. Rebooking while preserving one Advance payment.
15. Payroll with required Z-reading closes and DRAFT -> APPROVED -> PAID locking.
16. Profit Sharing with period-end percentage snapshots and frozen Approved/Paid history.

A module is parity-complete only after database, permission, API, React UI, audit, downstream effects, error paths, idempotency/concurrency where relevant, import mapping and regression tests all pass.


## 23. Required repository continuity documents

Before the first production implementation package, create these documents in the actual repository:

- `docs/YODAMEN_BUSINESS_RULES.md`
- `docs/PARITY_MATRIX.md`
- `docs/TRANSACTION_WATERFALL.md`
- `docs/MIGRATION_STATUS.md`
- `docs/LEGACY_SOURCE_MAP.md`

Every material change to Sales, Payments, Packages, Service Credits, Commission, Payroll or Profit Sharing updates the applicable documents and automated tests in the same Git checkpoint.


## 24. Git and delivery workflow

Use the user's **active development branch** for every migration package. The ZIP does not include `.git`, so the first implementation session must verify the current branch before files are changed. A clean checkpoint should be committed before each package. After implementation, run backend tests, frontend lint/build and database migration tests, then commit the verified package. Merge to `stable/approved` only after the user approves the phase and its parity checks.

For future coding responses, every delivery must identify **ADD NEW**, **REPLACE EXISTING**, **MODIFY**, and **DO NOT CHANGE** paths. Full-file replacements are preferred when practical. Each package must include migration/rollback implications, exact commands, test steps, expected results and regression checks.

The repository inspection requirement is now satisfied. Production implementation remains intentionally blocked only by the user's approval of this final blueprint.
## 25. Blueprint approval gate and smallest safe next implementation package

This Revision 2 blueprint is the approval gate. No YODAMEN production module code has been generated or modified during the audit.

After approval, the smallest safe package is **Phase 1A: Foundation Configuration + Identity/Security Skeleton**. It should establish infrastructure needed by every later module without prematurely implementing Booking, Sales or Payroll.

### 25.1 Expected Phase 1A file plan (proposal only, no code yet)

**MODIFY EXISTING**

- `backend/.env.example` — document MySQL `yodamen_dev`, YODAMEN app name/timezone and SPA/Sanctum environment variables without real secrets.
- `backend/config/app.php` — make application/business timezone configuration explicit.
- `backend/routes/api.php` — preserve `/api/health`; introduce versioned auth/bootstrap route group.
- `backend/app/Models/User.php` — adapt identity fields/relationships only after the users migration design is approved.
- `backend/database/seeders/DatabaseSeeder.php` — replace generic Laravel test-user behavior with environment-safe YODAMEN seed orchestration.
- `frontend/index.html` — YODAMEN title/metadata.
- `frontend/src/main.jsx` — install the application router/providers.
- `frontend/src/App.jsx` — replace health-check-only screen with application route shell after API client/health handling moves to dedicated modules.
- `frontend/src/index.css` — establish YODAMEN design tokens/base styles.

**ADD NEW, proposed Laravel foundation paths**

- `backend/app/Models/Business.php`
- `backend/app/Models/Branch.php`
- `backend/app/Models/Role.php`
- `backend/app/Models/Permission.php`
- `backend/app/Models/AuditLog.php`
- `backend/app/Models/CodeSequence.php`
- `backend/app/Models/IdempotencyRequest.php`
- foundation migrations for business/branch/security/audit/idempotency structures
- `backend/app/Http/Controllers/Api/V1/AuthController.php`
- `backend/app/Http/Controllers/Api/V1/BootstrapController.php`
- focused Form Requests for login/change credential
- authorization policies/middleware/services consistent with Laravel 13 conventions
- seeders for permission catalog and protected Super Admin role
- Pest feature tests for auth, permissions, Super Admin protection, audit and health endpoint

**ADD NEW, proposed React foundation paths**

- `frontend/src/router/index.jsx`
- `frontend/src/api/client.js`
- `frontend/src/features/auth/*`
- `frontend/src/layouts/AppLayout.jsx`
- `frontend/src/components/navigation/*`
- `frontend/src/context/AuthContext.jsx` or an equivalent lightweight auth provider
- `frontend/src/pages/LoginPage.jsx`
- `frontend/src/pages/DashboardPage.jsx` as a shell/placeholder only, not a completed dashboard module
- YODAMEN UI primitives needed for sidebar/header/status/buttons/forms

**DO NOT CHANGE in Phase 1A**

- No Booking/Queue/Visit business logic yet.
- No Sales/Payment/Package/Commission/Payroll schema yet beyond shared idempotency/audit infrastructure.
- No historical data import yet.
- No removal of Laravel default Blade/Vite assets solely because the separate React frontend exists.
- No new global state/query dependency unless a concrete need is demonstrated and approved.

### 25.2 Phase 1A acceptance gate

Phase 1A is complete only when:

1. Laravel connects to the MySQL development database using environment configuration, without credentials committed to source.
2. React can authenticate against Laravel using the approved Sanctum SPA/session flow.
3. Role + module/action permissions are enforced server-side.
4. Super Admin permission protection is covered by tests.
5. Important identity/permission mutations create SQL audit records.
6. YODAMEN business timezone behavior is explicit and covered by a date-bound test.
7. `/api/health` still works.
8. Frontend lint/build and backend Pest tests pass.
9. Required repository continuity documents are created/updated.
10. No Operations or financial module behavior has been prematurely implemented.

Only after that gate should Phase 1B Services/Pricing/Personnel masters begin.