# YODAMEN Phase 7A — Retail POS + Product / SKU Master — Update 62

## Purpose
Activate a standalone Retail POS under Sales and a real Product / SKU Master under Inventory. Standalone retail sales no longer need a Booking, Queue, or Visit.

## Prerequisite
Install this on the current YODAMEN source that already includes Updates 59–61 (split checkout payment, retail commission scope, and retail-only servicing fix).

## What Update 62 adds

### Sales → Retail POS
- Direct standalone retail sale. No Booking/Visit is required.
- Optional existing Customer.
- Required Assigned Staff on every finalized product line.
- Search by product name, SKU, barcode, category, or brand.
- Exact SKU/barcode + Enter supports scanner-style cart entry.
- Quantity control with live branch available stock.
- Server-side discount/promo preview before payment validation.
- Split payment rows with Payment Method, Amount, and optional Reference No.
- Finalize is valid only when Split Payment Total exactly equals the server-calculated net checkout amount.
- Finalization creates Sale, Sale Items, Payments, Payment Applications, inventory movements, and retail commission earnings inside one database transaction.
- Retail Sales History is available from the POS module.

### Inventory → Product / SKU Master
Product fields include:
- SKU
- Barcode
- Product Name
- Category
- Brand
- Unit
- Unit Cost
- Selling Price
- Reorder Point
- Commission Eligible
- Status
- Notes

Inventory is branch-specific.
- On Hand
- Reserved
- Available = On Hand - Reserved
- Controlled stock adjustment with reason
- Append-style Inventory Movement history
- Retail POS finalization deducts stock automatically

### Retail Commission Rules
Retail Purchase commission rules can now target:
- All Retail Products
- Retail Category
- Specific Product / SKU

Existing Service commission rules remain separate.
Retail commission is posted only after the POS Sale is financially finalized.

## Important financial rules
1. Retail POS creates a direct Sale with `sale_channel = RETAIL_POS`.
2. Customer is optional.
3. Booking and Visit are not fabricated for standalone retail sales.
4. Product quantity cannot exceed branch Available Stock.
5. Inventory deduction happens only on finalized POS sale.
6. Split payment total must equal the final net checkout amount after discounts.
7. Sale Item preserves SKU, product name, unit price, unit cost, discount, net amount, and assigned staff snapshots.
8. Retail commission uses the existing auditable Commission Earnings lifecycle.
9. The transaction is atomic: if payment, inventory, or financial finalization fails, the Retail POS sale is not partially posted.

## Database migration
Adds:
- `retail_products`
- `inventory_stocks`
- `inventory_movements`
- `sales.sale_channel`
- retail-product snapshot links on `sale_items`
- retail product/category targeting on `commission_rules`
- nullable Booking / Visit / Customer links needed for direct POS sales

Migration file:
`backend/database/migrations/2026_09_18_007000_create_retail_product_master_and_pos.php`

## Permissions
New permission modules:
- `retail-pos`: VIEW, CREATE
- `inventory-master`: VIEW, CREATE, EDIT, ADJUST

Seeder:
`Database\\Seeders\\RetailPosPermissionSeeder`

Default seeding grants:
- Super Admin: all Retail POS and Product Master permissions
- Manager: Retail POS + Product Master management/adjustment
- Reception: Retail POS + Product Master view

## Installation
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-RETAIL-POS-SKU-MASTER-62.zip -d .

cd backend
php artisan migrate
php artisan db:seed --class=RetailPosPermissionSeeder
```

Do not use `php artisan migrate:fresh`.

## Focused tests

```bash
php artisan test --filter=RetailProductTest
php artisan test --filter=RetailPosTest
php artisan test --filter=CommissionRuleTest
php artisan test --filter=CheckoutSettlementTest
```

Then full backend regression:

```bash
php artisan test
```

Frontend build:

```bash
cd ../frontend
npm run build
```

## Expected user flow

### Initial setup
1. Open Inventory → Product / SKU Master.
2. Create a product with SKU, selling price, cost, category, etc.
3. Use Stock Adjustment to load opening stock for the branch.
4. If commission applies, create/update a Commission Rule with scope `Retail Purchases`, then optionally target a Retail Category or Specific Product / SKU.

### Retail sale
1. Open Sales → Retail POS.
2. Select branch and optional customer.
3. Scan/search SKU or barcode and add product.
4. Select quantity and Assigned Staff.
5. Enter promo code if applicable and preview the final net amount.
6. Enter one or more payment methods.
7. Split Payment Total must equal Net Checkout Total.
8. Finalize.
9. Stock is deducted and eligible retail commission is posted.

## Current boundary
This update activates Product/SKU Master, branch stock, and direct Retail POS. Return/refund UI and supplier purchasing/replenishment workflows are not included in this phase. Historical finalized sales should continue to be corrected through auditable reversal/refund workflows rather than deletion.
