# Phase 2C Change Manifest

## Scope

Phase 2C activates the Visit + Servicing foundation while keeping all financial finalization outside Operations.

### Added

- `visits`
- `visit_service_lines`
- `visit_retail_lines`
- `service_completion_events`
- Queue → Start Servicing action
- one Visit per Queue entry database constraint
- Visit service-line staff assignment
- Visit service-line completion
- Visit service-line cancellation with mandatory reason
- mixed completed/cancelled Visit outcome handling
- add NEW_SERVICE to an active Visit
- Servicing React page
- Servicing VIEW parity seeder for legacy Manager/Reception/Barber role visibility
- automated Servicing tests

### Preserved boundaries

- Visit ID is created only when Start Servicing is executed.
- Walk-In continues to use Booking → Queue → Visit.
- Initial Queue service lines become Visit service lines with separate staff snapshots.
- service completion is operational only.
- no payment, Sale, commission, package settlement, or accounting entry is created from service completion.
- Service Credit completion remains disabled until the Package/Service Credit phase.
- completed service creates one append-only `service_completion_events` row for downstream consumers.

### Files added

Backend:

- `database/migrations/2026_09_12_000500_create_visit_servicing_tables.php`
- `app/Models/Visit.php`
- `app/Models/VisitServiceLine.php`
- `app/Models/VisitRetailLine.php`
- `app/Models/ServiceCompletionEvent.php`
- `app/Services/Operations/VisitService.php`
- `app/Http/Controllers/Api/V1/Operations/ServicingController.php`
- `app/Http/Requests/Api/V1/Operations/AddVisitServiceRequest.php`
- `app/Http/Requests/Api/V1/Operations/AssignVisitServiceStaffRequest.php`
- `app/Http/Requests/Api/V1/Operations/CancelVisitServiceRequest.php`
- `database/seeders/ServicingPermissionSeeder.php`
- `tests/Feature/Operations/ServicingTest.php`

Frontend:

- `src/pages/operations/ServicingPage.jsx`

### Files modified

- `backend/routes/api.php`
- `backend/app/Models/Booking.php`
- `backend/app/Models/Customer.php`
- `backend/app/Models/QueueEntry.php`
- `backend/app/Services/Operations/QueueService.php`
- `backend/app/Http/Controllers/Api/V1/Operations/QueueController.php`
- `backend/database/seeders/FoundationSeeder.php`
- `frontend/src/pages/operations/QueuePage.jsx`
- `frontend/src/components/navigation/navigationConfig.js`
- `frontend/src/router/index.jsx`
- `frontend/src/index.css`
- migration continuity documents under `docs/`
