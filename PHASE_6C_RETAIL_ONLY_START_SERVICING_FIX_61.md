# YODAMEN Phase 6C — Retail-Only Start Servicing Fix 61

## Purpose
Fixes the retail-only flow introduced in Update 60.

Update 60 already allowed retail lines to flow through Booking, Queue, Visit, Sale Item, Checkout, Commission, and Payroll. One older service-only guard remained in `VisitService::startFromQueue()`, causing a retail-only queue entry to return HTTP 422 before its Visit was created.

## Fix
`startFromQueue()` now allows servicing to start when the Queue has either:
- at least one active service, or
- at least one retail purchase.

A Queue with neither still cannot start servicing.

## Files
REPLACE EXISTING:
- `backend/app/Services/Operations/VisitService.php`

## Database
No migration required.

## Tests
Run:

```bash
php artisan test --filter=ServicingTest
php artisan test --filter=CheckoutSettlementTest
php artisan test --filter=CommissionRuleTest
```

Then run the full suite:

```bash
php artisan test
```

## Expected regression checks
- Existing service-only Visits still start normally.
- Retail-only Visits can start and complete.
- Assigned retail staff is carried into the PRODUCT Sale Item.
- Retail commission is not created before Checkout finalization.
- Finalized retail Sale posts commission according to Retail Purchase Commission Rules.
- Existing Service Commission Rules remain separate from Retail rules.
