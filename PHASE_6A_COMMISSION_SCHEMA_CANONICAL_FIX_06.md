# YODAMEN Phase 6A — Commission Schema Canonical Fix 06

## Purpose

This update fixes inconsistent Commission Rule migrations exposed by SQLite feature tests.

The canonical `commission_rules` schema now supports the approved flexible rule model directly on a fresh database:

- Rule Name
- optional Branch
- Applies To: ALL / POSITION / EMPLOYEE
- Personnel
- Position
- Specific Service
- Service Category
- Percentage / Fixed
- legacy `rate_percentage` compatibility mirror
- canonical `rate_value`
- Standard Service Price basis
- effective dates
- Priority
- Active / Inactive
- audit users/timestamps

## Files to REPLACE

- `backend/database/migrations/2026_09_17_002000_create_commission_rules.php`
- `backend/database/migrations/2026_09_17_002100_expand_commission_rules_for_flexible_scope.php`
- `backend/database/migrations/2026_09_17_002200_align_commission_rules_to_personnel.php`

## File to ADD

- `backend/database/migrations/2026_09_17_002300_reconcile_commission_rules_schema.php`

## Files NOT changed

- Commission Rule model/service/controller/request
- Personnel logic
- React frontend
- Sales/payment/package logic
- Commission posting remains inactive

## Migration behavior

Fresh databases/tests:
- `002000` creates the final flexible schema immediately.
- `002100` and `002200` are idempotent compatibility migrations.

Existing MySQL database:
- prior migrations remain in Laravel migration history.
- new `002300` reconciles any missing flexible-rule columns and legacy nullability/index requirements.
- no existing commission-rule rows are deleted.

## Run

```bash
php artisan migrate:status
php artisan migrate
php artisan optimize:clear
php artisan test tests/Feature/Commissions/CommissionRuleTest.php
php artisan test tests/Feature/MasterData/PersonnelTest.php
php artisan test
```

Do not run `migrate:fresh`.
