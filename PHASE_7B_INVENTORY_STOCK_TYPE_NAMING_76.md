# YODAMEN Update 76 — Inventory Stock Type & Naming

## Navigation labels
Inventory navigation is standardized to:
- **Dashboard**
- **Masterlist**
- **Movements**
- **Audit Count**
- Adjustments
- Reorder Monitoring

`Audit Count` is the new label for the future Inventory Count workflow; this update does not fabricate the physical-count workflow before it is built.

## Stock Type
Adds **Stock Type** to the product master:

### Inventory
For quantity-tracked items intended for retail sale and/or service consumption. These can participate in stock balances, stock adjustments, Retail POS, Booking/Walk-In retail selection, reorder monitoring, and retail commission targeting.

### Non-inventory
For equipment, office/shop supplies, and similar items that should not behave like sale/service stock. These remain in Masterlist but are excluded from Retail POS, Booking/Walk-In retail selection, reorder monitoring, stock adjustment operations, and retail commission selection.

Existing products default to `INVENTORY` to preserve current behavior.

## Installation
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7B-INVENTORY-STOCK-TYPE-NAMING-76.zip -d .
cd backend
php artisan migrate
```

Do not use `migrate:fresh`.

## Focused tests
```bash
php artisan test --filter=RetailProductTest
php artisan test --filter=InventoryOperationsTest
php artisan test --filter=RetailPosTest
php artisan test --filter=CommissionRuleTest
```

Then:
```bash
php artisan test
cd ../frontend
npm run build
```
