# YODAMEN Phase 6C — Retail Purchase + Assigned Staff + Commission — Update 60

## Purpose
Activate the existing retail/product-purchase flow as a complete operational and financial path and connect it to assigned staff and the commission engine.

## Business rules implemented

1. Retail product lines can carry an Assigned Staff member from Booking/Walk-In through Queue, Visit, Sale Item, and Commission Earning.
2. A Visit may be retail-only. It no longer requires a service line in order to reach Completed/Checkout when a valid retail purchase exists.
3. Every retail line must have an active, commission-eligible Assigned Staff before the Visit can be completed.
4. Retail commission is created only after the Sale is financially finalized. Adding a retail line or completing the Visit alone does not post commission.
5. Existing Service commission rules remain Service-only. The migration defaults all existing rules to `SERVICE`.
6. Commission Rules now support two scopes:
   - `Services`
   - `Retail Purchases`
7. Until an Inventory/Product master is activated, Retail Purchase rules apply to all retail products within the chosen Staff/Position/Branch scope. Product-specific targeting is intentionally not fabricated from free-text product names.
8. Retail percentage commission uses the product Sale Item's net line value after discounts (`NET_RETAIL_SALE`).
9. Retail fixed commission is applied per product quantity.
10. Retail commission earnings remain auditable and use the same OPEN / payroll / reversal lifecycle as service commissions.
11. Update 59 split-payment checkout remains fully supported and is automatically available to retail-only and mixed checkouts.

## Operational flow

Customer / Booking or Walk-In
→ Product Purchase
→ Assigned Staff
→ Queue
→ Visit / Manage Product Purchases
→ Complete Retail Visit (or complete service + retail visit)
→ Sale draft with PRODUCT Sale Item
→ Checkout / Split Payment
→ Financial Finalization
→ Retail Commission Earning
→ Daily Compensation / Payroll

## UI changes

### Bookings
Product Purchase rows now include Assigned Staff. Assignment may be completed later during Servicing.

### Queue / Walk-In
Product Purchase rows now include Assigned Staff.

### Servicing
Product Purchase rows require Assigned Staff. Retail-only visits can be completed using `Complete Retail Visit`.

### Commission Rules
A new Commission Scope control separates `Services` from `Retail Purchases`.

Retail rules support:
- All Staff
- Position
- Specific Employee
- optional Branch
- Percentage or Fixed amount
- effective dates / priority / status

### Commission Summary / My Payroll
Retail commissions are identified as `Retail Purchase` and use `Net Retail Sale` as the displayed basis.

## Database migration

New migration:

`backend/database/migrations/2026_09_18_006000_activate_retail_purchase_commissions.php`

It adds:
- Assigned Personnel fields to Booking / Queue / Visit retail lines
- `commission_scope` to Commission Rules
- Retail source fields to Commission Earnings
- nullable Service/Visit Service references for retail earnings

Existing commission rules default to `SERVICE`.

## Installation

From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6C-RETAIL-PURCHASE-COMMISSION-60.zip -d .

cd backend
php artisan migrate
```

Do not use `php artisan migrate:fresh`.

## Focused tests

```bash
php artisan test --filter=CommissionRuleTest
php artisan test --filter=ServicingTest
php artisan test --filter=CheckoutSettlementTest
```

Then full backend regression:

```bash
php artisan test
```

Then frontend build:

```bash
cd ../frontend
npm run build
```

## Expected regression coverage

The package adds/updates coverage for:
- Retail and Service commission scopes remain separate
- Retail-only Visit can complete
- Assigned retail staff reaches the PRODUCT Sale Item
- No retail commission before checkout financial finalization
- Retail commission posts after finalized checkout
- Percentage retail commission uses net retail Sale value
- Existing package-session and service commission behavior remains intact
- Existing split-payment checkout remains intact

## Important current boundary

Retail Purchase is now operational and commission-aware, but this update does not invent an Inventory Product master that does not yet exist. Current retail entries remain the existing free-text Product Purchase lines. When the Inventory/Product master is activated later, retail lines can be linked to SKU/product records and stock deductions without changing the commission lifecycle introduced here.
