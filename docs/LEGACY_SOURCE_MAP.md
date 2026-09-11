# YODAMEN Legacy Source Map

This map identifies how active Apps Script responsibilities move into canonical React/Laravel/MySQL areas. Historical V-number aliases and repair scripts are evidence, not target runtime architecture.

| Legacy source | Purpose/evidence | Target area | Migration status |
| --- | --- | --- | --- |
| `Auth.js` | Login, session validation, failed login lock, permissions, Force Reset | Laravel `AuthController`, User security fields, Sanctum, permission middleware | Phase 1A DONE |
| `RolePermissions2026.js` | Action-level permission matrix; Super Admin restriction; auto-VIEW behavior | `RolePermissionService`, `permissions`, `role_permissions` | Phase 1A DONE |
| `Audit.js` | Basic append-only activity log | `AuditService`, `audit_logs`, request correlation | Phase 1A DONE |
| `Code.js` | Apps Script entry/bootstrap | React router/app shell + Laravel bootstrap API | Phase 1A DONE |
| `Config.js` / `Setup.js` | Business, branch, timezone, roles and seed defaults | Business/Branch/Role seeders and config | Phase 1A DONE |
| `Admin.js` | Admin masters, permission setup | Admin APIs/services by phase | Security subset DONE; remaining PLANNED |
| `AdminHTML.html` | Admin UI | React admin feature pages | PLANNED |
| `Index.html` / `App.html` | Global shell/router/navigation | React router, `AppLayout`, sidebar | Foundation shell DONE |
| `Styles.html` / `UXPolish.html` | Legacy visual system and patches | React design tokens/components | Foundation shell DONE; parity polish ongoing |
| `Database.js` | Sheet CRUD/scans | Eloquent/SQL repositories/actions | Retired pattern |
| `Customer.js` / `CustomerHTML.html` | Customer master/history | Customer domain + React page | PLANNED |
| `BookingVisits.js` / HTML / setup | Booking, Queue, Visit lifecycle | Operations domain | PLANNED |
| `ReceptionWalkInPermission2026.js` | Walk-In permission edge case | Operations policies/use case | PLANNED |
| `QueueVisitSetup.js` | Legacy queue/visit sheet structure | Operations migrations | PLANNED |
| `OperationsPerformance.js` / HTML | Operations screens and mixed performance behavior | Split React Operations pages/components | PLANNED |
| `ServiceCredit.js` / setup | Package/service-credit entitlement behavior | Packages domain | PLANNED |
| `Sales.js` / `SalesHTML.html` / `SalesSetup.js` | Sale/Payment base and Transactions UI | Sales domain + React Transactions | PLANNED |
| `ServicingPaymentAllocation.js` | Advance/checkout/collection allocation and package-balance targeting | PaymentApplication service/actions | PLANNED |
| `SalesPayrollBridge.js` | Finalization waterfall and commission/package bridge | Canonical FinalizeCheckout action + events | PLANNED |
| `SalesServiceLineSettlement2026.js` | Parent Pending, finalized line settlement, V33 integrity repair | Settlement read model + SQL uniqueness | PLANNED; repair logic import-only |
| `ServiceBalanceFinancial2026.js` | Per-enrollment package financial monitoring | PackageEnrollment financial read model | PLANNED |
| `CommissionMultiStaffFix.js` | Multi-staff service-line commission reconciliation | Commission resolver/reconciliation | PLANNED |
| `SalesDashboard.js` / rankings | Sales KPI/ranking views | SQL read models + React dashboard | PLANNED |
| `ZReadings.js` / HTML | Read-only daily sales report | Persistent ZReading close aggregate | PLANNED |
| `HRPayroll.js` / `HRHTML.html` | Personnel, compensation, CA, commissions, payroll | HR domains split into focused services/pages | PLANNED |
| `YodamenAttendanceV19.gs.js` | Self-service/admin attendance with proof | Attendance domain/private storage | PLANNED |
| `YodamenHRServicingV6Bridge.js` | HR/servicing compatibility bridge | Retired after canonical domain boundaries | PLANNED/retire |
| `PartnerProfitProfileV2.js` | Partner profile enrichment | Personnel/Profit Sharing read model | PLANNED |
| `ProfitSharing.js` / HTML | Profit Sharing run lifecycle | ProfitSharing domain | PLANNED |
| `OperationsPaymentStatus.js` / `OperationsPaymentFastBridge.js` | Payment-status compatibility helpers | Derived Laravel financial read models | PLANNED/retire wrappers |
| Diagnostics (`BookingDiagnostic*`, `PaymentDebug`, `PaymentTraceDiagnostic`, `HRPayrollV9Diagnostics`) | Debug/integrity knowledge | Automated tests/import validators | Do not port as runtime |
| `DevelopmentReset.js` | Test-data cascade reset | Test factories/seeders/dev-only Artisan command | Do not expose in production |
| `ModalLayoutGuard.html` | DOM compatibility patch | React component layout | Retired pattern |
| `SalesAccessBridge2026.js`, `UserAccessBranding2026.js`, `PublicBranding2026.js` | Access/branding compatibility | Canonical permission/bootstrap/branding services | Security subset DONE; branding PLANNED |
