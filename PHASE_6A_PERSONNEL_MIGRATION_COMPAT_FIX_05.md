# Phase 6A Personnel Migration Compatibility Fix 05

## Purpose
Fix the `2026_09_17_002200_align_commission_rules_to_personnel.php` migration so fresh SQLite test databases and existing MySQL development databases can both migrate safely.

## Files to REPLACE
- `backend/database/migrations/2026_09_17_002200_align_commission_rules_to_personnel.php`

## Files to ADD
- None

## Files to NOT CHANGE
- Personnel business logic
- Commission rule resolver
- Commission rule UI
- Sales / Payments / Package / Service Credit logic

## Behavior
- Adds `personnel_id` only when missing.
- Copies legacy `employee_id` values only when the legacy column exists.
- Removes the legacy `employee_id` only when present.
- Ensures the personnel index exists without assuming a specific fresh-schema state.
- Down migration performs the corresponding compatibility-safe reversal.

## Migration
No new migration was added. Existing development databases that already ran migration `002200` do not need to run it again. Fresh test databases will automatically use the corrected migration.
