# YODAMEN Update 64 — Product Masterlist E-Commerce UI

## Scope

This update refines Inventory → Product Masterlist without changing existing retail sale history or stock balances.

### Product Masterlist
- Renames `Product / SKU Master` to `Product Masterlist`.
- Removes the Products / On Hand Units / Low Stock SKUs summary cards.
- Uses an e-commerce-style listing with SKU / Barcode, Product, Category / Brand, UOM, Cost, Retail Price, Stock, Reorder, Commission, Status, and Actions.
- Groups the Add/Edit Product form into Product Identity, Classification, Pricing & Inventory, and Settings.
- Makes the Commission Eligible checkbox compact.

### SKU generation
- New Product Masterlist entries can leave SKU blank.
- Laravel generates a stable SKU after the product row is created using `SKU-000001` style formatting.
- SKU remains stable even if category, brand, or product name changes.
- Existing/manual SKUs remain supported for API compatibility and existing product records.

### Reference Lists
Adds the following Reference List categories:
- Product Category
- Product UOM
- Product Brand

Default Product Category options:
- Hair Care
- Scalp Care
- Styling
- Beard Care
- Skin Care
- Grooming Essentials
- Tools & Accessories
- Other

Default Product UOM options:
- pc
- bottle
- tube
- jar
- pack
- box
- sachet
- set

Product Brand starts empty so administrators can maintain their own brands under Reference Lists.

Product Category references are also exposed to Retail Commission Rules, while categories already used by older products remain available.

## Install

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-PRODUCT-MASTERLIST-ECOM-UI-64.zip -d .
cd backend
php artisan db:seed --class=ProductMasterReferenceSeeder
```

No database migration is required.

## Tests

```bash
php artisan test --filter=RetailProductTest
php artisan test --filter=CommissionRuleTest
php artisan test --filter=RetailPosTest
```

Then:

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Expected result
- Sidebar shows `Product Masterlist`.
- Product Masterlist no longer shows the three summary counters.
- New products show `Auto-generated on save` in SKU Code and receive a stable `SKU-######` code after saving.
- Category, Brand, and UOM are dropdowns backed by Reference Lists.
- Commission Eligible is a small compact checkbox/control instead of the oversized global checkbox styling.
