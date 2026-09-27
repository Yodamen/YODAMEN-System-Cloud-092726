# Phase 6A Commission Migration + API Fix 07

This update fixes the two remaining failures reported after Update 06.

## Fixes

1. **MySQL migration 002100**
   - Adds a non-unique `service_id` support index before removing the legacy `(service_id, effective_from)` unique index.
   - This preserves the existing `service_id` foreign-key requirement while allowing the old uniqueness rule to be removed.
   - 002300 receives the same defensive logic.

2. **Commission Rules index API**
   - Replaces the controller with the flexible-rules version that calls `formOptions()` rather than removed legacy helper methods such as `availableServices()`.

3. **Commission Rule edit/toggle ownership check**
   - Normalizes `business_id` to integer before strict comparison so valid same-business rules do not incorrectly return 404 because of PHP scalar type differences.

## Files to REPLACE
- `backend/database/migrations/2026_09_17_002100_expand_commission_rules_for_flexible_scope.php`
- `backend/database/migrations/2026_09_17_002300_reconcile_commission_rules_schema.php`
- `backend/app/Http/Controllers/Api/V1/Admin/CommissionRuleController.php`
- `backend/app/Services/Commissions/CommissionRuleService.php`

## Files to ADD
- None.

## Files NOT changed
- Commission business hierarchy
- Personnel rules
- Commission posting state (still disabled)
- Frontend Commission Rules screen
- Existing transaction/payment logic

## Migration
`002100` previously failed and should not be marked as applied. After replacing these files, rerun `php artisan migrate`. Do not use `migrate:fresh`.
