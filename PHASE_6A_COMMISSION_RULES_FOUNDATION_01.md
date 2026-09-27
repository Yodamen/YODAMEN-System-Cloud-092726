# YODAMEN Phase 6A — Commission Rules Foundation 01

## Scope
- Adds effective-dated service commission rules.
- Enables Administration → Commission Rules.
- Rate basis is Collected Service Amount.
- Personnel commission eligibility remains the staff-level gate.
- Commission posting/ledger is intentionally NOT active yet.

## Business rule preserved
Operational service completion alone does not create commission. Phase 6B will post commissions only after qualifying financial confirmation.

## Migration
Required: `2026_09_17_002000_create_commission_rules.php`

## Validation
Backend:
- `php artisan migrate:status`
- `php artisan migrate`
- `php artisan optimize:clear`
- `php artisan test`

Frontend:
- `npm run lint`
- `npm run build`
- `npm run dev`
