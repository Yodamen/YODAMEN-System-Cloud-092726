# YODAMEN Phase 5B - Multi-Service Completion 08

## Purpose
Remove the second Complete Service modal from Servicing > Manage and allow multiple active services to be completed directly from the Manage window.

## Behavior
- Each completable regular service has a checkbox.
- Each completable Service Credit service has a checkbox.
- Assigned Staff remains editable in Manage.
- Add Service remains available in Manage.
- Claim Service Credit remains available in Manage.
- Product Purchase remains available in Manage.
- Product Purchase edits are included when completing selected services.
- Select all active services is available.
- Complete Selected Services completes the checked service lines in one backend request.
- Completed or cancelled service lines cannot be selected.
- The former per-service Complete button and second completion modal are removed.
- Service Credit is still consumed only when its service line is completed.
- Visit finalization continues to occur only when all active service lines are finalized.

## Database Migration
None.

## Backend
Adds a batch completion endpoint:

POST /api/v1/operations/servicing/{visit}/complete-services

The request accepts selected Visit Service Line IDs and current Visit retail items. Selected service lines are validated as belonging to the Visit and are completed inside one outer database transaction.

## Validation
Run backend tests and frontend lint/build after installation.
