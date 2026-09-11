# YODAMEN Parity Matrix

Legend: `DONE` foundation parity implemented and tested in target code; `PLANNED` not yet migrated; `FUTURE` placeholder module; `VERIFY-DATA` requires historical export reconciliation.

| Area | Legacy behavior/source | Laravel | React | MySQL | Automated test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Health | Existing React/Laravel smoke check | `/api/health` retained | API client available | n/a | Health feature test | DONE |
| Username + Access Code login | `Auth.js` | Sanctum/session login with failed-attempt lock | Login page | User security fields | Authentication tests | DONE |
| Failed login lock | 5 attempts / 15 minutes | Server enforced | Error surfaced | `failed_attempts`, `locked_until` | Lock test | DONE |
| Force Reset | `Auth.js` + `App.html` modal | Access-code change endpoint | Blocking reset modal | `force_reset` | Change-code test | DONE |
| Session target | Apps Script cache 360 minutes | DB session config 360 minutes | Cookie SPA auth | `sessions` | Config/review | DONE |
| Roles | `REF_Roles` | Role model + seed | Permission-aware shell | `roles` | Permission tests | DONE |
| Action permissions | `REF_Permissions`, `requirePermission_` | Middleware + service | Navigation checks | `permissions`, `role_permissions` | Permission tests | DONE |
| Super Admin protection | `ROLE-SA` guard | Restriction blocked | n/a | protected seeded role | Super Admin test | DONE |
| Business | `REF_Business` | Business model | Bootstrap display | `businesses` | Seeder/bootstrap coverage | DONE |
| Branch | `REF_Branches` | Branch model | Bootstrap display | `branches` | Seeder/bootstrap coverage | DONE |
| Audit | `Audit.js` | Append-only AuditService | n/a | `audit_logs` | Login/permission audit tests | DONE |
| Request correlation | None equivalent | Request middleware | n/a | audit request ID | Header/audit path | DONE |
| Code generator | human-readable codes | transactional sequence service | n/a | `code_sequences` | Sequence test | DONE |
| Idempotency foundation | Apps Script dedupe/locks | reusable idempotency service | n/a | `idempotency_requests` | Replay test | DONE |
| Business timezone | `SYS_Config` Asia/Manila | BusinessClock | business date shown | configuration | UTC-boundary test | DONE |
| YODAMEN shell | `Index.html` / `App.html` | bootstrap API | dark/gold authenticated shell | n/a | frontend build/lint | DONE |
| Customers | `Customer.js` | PLANNED | PLANNED | PLANNED | PLANNED | PLANNED |
| Booking | `BookingVisits.js` | PLANNED | PLANNED | PLANNED | waterfall cases | PLANNED |
| Queue | Booking/Queue source | PLANNED | PLANNED | PLANNED | waterfall cases | PLANNED |
| Visit / Servicing | BookingVisits + UI | PLANNED | PLANNED | PLANNED | waterfall cases | PLANNED |
| Sales creation | `Sales.js` | PLANNED | PLANNED | PLANNED | race/idempotency cases | PLANNED |
| Payment ledger | `Sales.js` | PLANNED | PLANNED | PLANNED | waterfall cases | PLANNED |
| Payment allocation | `ServicingPaymentAllocation.js` | PLANNED | PLANNED | PLANNED | allocation cases | PLANNED |
| Finalized settlement tabs | `SalesServiceLineSettlement2026.js` | PLANNED | PLANNED | derived query | mixed-sale cases | PLANNED |
| Package Enrollment | Service Credit sources | PLANNED | PLANNED | PLANNED | package cases | PLANNED |
| Service Credits | `ServiceCredit.js` | PLANNED | PLANNED | PLANNED | package/credit cases | PLANNED |
| Service Balances | `ServiceBalanceFinancial2026.js` | PLANNED | PLANNED | PLANNED | financial cases | PLANNED |
| Commission | bridge + multi-staff fix | PLANNED | PLANNED | PLANNED | multi-staff/reversal | PLANNED |
| Attendance | `YodamenAttendanceV19` | PLANNED | PLANNED | PLANNED | attendance privacy | PLANNED |
| Daily Compensation | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | compensation tests | PLANNED |
| Cash Advances | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | payroll tests | PLANNED |
| Payroll | `HRPayroll.js` | PLANNED | PLANNED | PLANNED | payroll lifecycle | PLANNED |
| Z-Reading | `ZReadings.js` + HR hook | PLANNED | PLANNED | PLANNED | close/payroll gate | PLANNED |
| Profit Sharing | `ProfitSharing.js` | PLANNED | PLANNED | PLANNED | lifecycle tests | PLANNED |
| Future Accounting | navigation/target rules | FUTURE | FUTURE | FUTURE | FUTURE | FUTURE |
| Future Inventory | navigation/target rules | FUTURE | FUTURE | FUTURE | FUTURE | FUTURE |
| Reports | navigation/target rules | FUTURE | FUTURE | read models | FUTURE | FUTURE |
| Historical Google Sheets data | live sheet rows | importer PLANNED | review UI/report PLANNED | target tables | reconciliation suite | VERIFY-DATA |
