# YODAMEN Phase 6A — Commission Edit Binding Fix 08

## Purpose
Fix the remaining CommissionRuleTest edit failure where PUT /api/v1/admin/commission-rules/{id} returned 404 even though the rule had just been created.

## Root correction
- Commission Rule update/toggle no longer depend on implicit Eloquent route-model binding.
- Controller accepts the route Rule ID explicitly.
- Service resolves the rule using both authenticated Business ID and Rule ID inside the same DB transaction with a row lock.
- Cross-business rule IDs still return 404.
- Existing rule row is updated in place; no replacement/history-row creation.

## Files replaced
- backend/app/Http/Controllers/Api/V1/Admin/CommissionRuleController.php
- backend/app/Services/Commissions/CommissionRuleService.php

## Migration
None.

## Business rules changed
None.
