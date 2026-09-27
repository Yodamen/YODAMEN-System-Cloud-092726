# YODAMEN Phase 6A - Commission Rule Route Fix 09

Purpose: eliminate the remaining Commission Rule edit 404 by aligning the PUT/PATCH routes with explicit scalar rule IDs.

## Replace
- backend/routes/api.php
- backend/app/Http/Controllers/Api/V1/Admin/CommissionRuleController.php
- backend/app/Services/Commissions/CommissionRuleService.php

## Add
- none

## Do not change
- database schema/migrations
- Commission Rule resolver hierarchy
- Personnel logic
- frontend

## Migration
None.

## Behavior
- PUT /api/v1/admin/commission-rules/{commissionRuleId}
- PATCH /api/v1/admin/commission-rules/{commissionRuleId}/toggle
- controller receives a scalar ID, not implicit model binding
- service performs authoritative lookup by authenticated user's business_id + rule ID
- update remains in-place; historical earning snapshots are unaffected

## Validate
php artisan optimize:clear
php artisan route:list --path=commission-rules
php artisan test tests/Feature/Commissions/CommissionRuleTest.php
php artisan test tests/Feature/MasterData/PersonnelTest.php
php artisan test
