# YODAMEN Legacy Source Map

Historical V-number aliases and repair scripts are implementation evidence. The target keeps one canonical implementation per use case.

| Legacy source | Purpose/evidence | Target area | Migration status |
| --- | --- | --- | --- |
| `Auth.js` | Login/session/failed lock/Force Reset | Laravel Auth + Sanctum | Phase 1A DONE |
| `RolePermissions2026.js` | action permissions/Super Admin guard | permission middleware/service | Phase 1A DONE |
| `Audit.js` | activity log | SQL AuditService | Phase 1A DONE |
| `Code.js`, `Config.js`, `Setup.js` | bootstrap/business/branch/timezone | foundation config/models | Phase 1A DONE |
| `Admin.js` Reference Lists | categories/options/create/edit/toggle | ReferenceDataService + API | Phase 1B IMPLEMENTED |
| `AdminHTML.html` Reference Lists | admin reference UI | React ReferenceListsPage | Phase 1B IMPLEMENTED |
| `ServicesSetup.js` | DB_Services, DB_ServicePrices, reference seeds | master-data migration/seeder | Phase 1B IMPLEMENTED |
| `Services.js` | service CRUD and effective price periods | ServiceCatalogService | Phase 1B IMPLEMENTED |
| `ServicesHTML.html` | service/pricing UI | React ServicesPricingPage | Phase 1B IMPLEMENTED |
| `HRPayroll.js` Personnel V10 | Employee/Partner, Position, Hourly, OT, Profit profile | PersonnelService/model | Phase 1B IMPLEMENTED |
| `HRHTML.html` Personnel | personnel modal/table | React PersonnelPage | Phase 1B IMPLEMENTED |
| `Database.js` | generic sheet CRUD/scans | Eloquent/SQL | retired pattern |
| `Customer.js` / HTML | customer master/history | Customer domain | PLANNED |
| `BookingVisits.js` / setup/UI | Booking → Queue → Visit | Operations domain | PLANNED |
| `ReceptionWalkInPermission2026.js` | Walk-In permissions | Operations policy | PLANNED |
| `OperationsPerformance.js` / HTML | Operations screens | split React pages/actions | PLANNED |
| `ServiceCredit.js` / setup | package/service credit | Packages domain | PLANNED |
| `Sales.js` / `SalesHTML.html` | Sale/Payment base + Transactions | Sales domain | PLANNED |
| `ServicingPaymentAllocation.js` | allocations/package target | PaymentApplication actions | PLANNED |
| `SalesPayrollBridge.js` | financial-finalization waterfall | FinalizeCheckout domain action/events | PLANNED |
| `SalesServiceLineSettlement2026.js` | parent Pending + line settlement + duplicate repair | read models + SQL constraints | PLANNED; repair import-only |
| `ServiceBalanceFinancial2026.js` | per-enrollment financials | Package Enrollment read model | PLANNED |
| `CommissionMultiStaffFix.js` | service-line commission reconciliation | Commission domain | PLANNED |
| `ZReadings.js` | daily sales view | persistent Z-reading close | PLANNED |
| `YodamenAttendanceV19.gs.js` | self/admin attendance proof | Attendance domain/storage | PLANNED |
| `ProfitSharing.js` | Profit Sharing lifecycle | ProfitSharing domain | PLANNED |
| Diagnostics/repair scripts | debugging and dirty-data evidence | tests/import validators | do not port runtime |
| `DevelopmentReset.js` | development cascade reset | factories/dev Artisan command | production route prohibited |


## Phase 2C mappings

| Legacy Apps Script / Sheet | Target |
|---|---|
| `DB_Visits` / `QueueVisitSetup.js` | `visits`, `Visit` model |
| `DB_VisitServices` / `BookingVisits.js` | `visit_service_lines`, `VisitServiceLine`, `VisitService` |
| `DB_VisitRetailItems` | `visit_retail_lines`, `VisitRetailLine` |
| `startVisitAdmin` | `POST /api/v1/operations/queue/{queueEntry}/start-servicing` |
| `startVisitServiceAdmin` | `POST /api/v1/operations/visit-services/{line}/start` |
| `assignVisitServiceStaffAdmin` | `PATCH /api/v1/operations/visit-services/{line}/staff` |
| `completeVisitServiceAdmin` | `POST /api/v1/operations/visit-services/{line}/complete` |
| `cancelVisitServiceAdmin` | `POST /api/v1/operations/visit-services/{line}/cancel` |
| `addVisitServiceAdmin` NEW_SERVICE branch | `POST /api/v1/operations/servicing/{visit}/services` |
| operational completion downstream hook | `service_completion_events` |
| `getServicingAdmin` / Servicing UI | `GET /api/v1/operations/servicing`, `ServicingPage.jsx` |

## Phase 3A mappings

| Legacy Apps Script / Sheet | Target |
|---|---|
| `DB_Sales` / `Sales.js` | `sales`, `Sale`, `SaleDraftService` |
| `DB_SalesItems` | `sale_items`, `SaleItem` |
| `DB_SalesPayments` | `payments`, `Payment`, `PaymentLedgerService` |
| `recordInitialAdvancePayment_` | Advance Payment creation inside Booking/Walk-In transaction |
| `getBookingAdvanceAmount_` | Payment-ledger aggregate by Booking |
| `syncSalesFromOperations_` | automatic `SaleDraftService::ensureForCompletedVisit()` + `yodamen:sales-sync` backfill command |
| `linkBookingAdvancePaymentsToSale_` | Sale creation links received Booking advances to Sale/Visit |
| Pending Transactions parent view | `GET /api/v1/sales/transactions/pending`, React `TransactionsPage` |
| Checkout detail read | `GET /api/v1/sales/transactions/{sale}/checkout` |
| Apps Script duplicate Sales repair | SQL unique `sales.visit_id` + transactional row locking; repair runtime not ported |
