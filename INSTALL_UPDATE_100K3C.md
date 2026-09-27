# YODAMEN Update 100K3C
## Accounting Mapping Fix + Stock Receipt Payment Accounting

This update is cumulative on top of 100K3 + 100K3A + 100K3B.

### What this fixes

1. **Accounting Mapping empty tables**
   - Fixes the backend `retail_products.item_name` lookup bug. The actual column is `name`.
   - Active inventory products now populate Inventory & COGS Mapping.
   - Active Payment Method reference values now populate Payment Method Mapping.
   - User-created COAs marked **Cash / Payment Account** remain selectable for payment methods.
   - Mapping page now displays API errors and useful empty-state instructions instead of silently showing blank tables.

2. **Stock Received payment fields**
   - Adds Payment Status: `UNPAID` or `PAID`.
   - Adds Payment Method for paid receipts.
   - Adds optional Payment Reference.
   - Paid receipts only accept payment methods already mapped under Admin → Accounting Mapping.
   - The receiving screen displays the mapped COA beside each selectable payment method.

3. **Stock Received automatic accounting**
   - Stock receipt accounting is now posted once at the receipt level instead of one independent AP journal per stock movement.
   - UNPAID receipt:
     - DR mapped Inventory account(s)
     - DR Inventory Freight-In / Delivery for delivery fee, if any
     - CR Accounts Payable – Suppliers
   - PAID receipt:
     - DR mapped Inventory account(s)
     - DR Inventory Freight-In / Delivery for delivery fee, if any
     - CR the cash/bank/e-wallet COA mapped to the selected Payment Method
   - Adds COA `5003 Inventory Freight-In / Delivery` for receipt delivery fees while preserving the existing product weighted-average-cost behavior.
   - Existing historical STOCK_RECEIVED movement journals are detected and are not duplicated by the new receipt-level posting logic.
   - `accounting:sync-current-state` now includes inventory receipts safely.

### Important setup after installation

Under **Admin → Reference Lists → Payment Method**, ensure the operational methods you use exist, for example:

- Cash
- GCash
- BDO Bank Transfer

Then go to **Admin → Accounting Mapping → Payment Method Mapping** and map them, for example:

- Cash → Cash on Hand
- GCash → E-wallet / POS Clearing - GCash
- BDO Bank Transfer → Cash in Bank - BDO

A COA does not automatically create a Payment Method. Both records are intentionally separate: the Payment Method is what staff selects operationally, while the mapping tells accounting which COA to post.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K3C-ACCOUNTING-MAPPING-STOCK-PAYMENTS.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/AccountingPaymentMappingTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Inventory/InventorySupplierUomTest.php

php artisan test

cd ../frontend
npm run build
```

Migration is required because this update adds Stock Received payment fields and COA 5003.

After the tests are green, you may safely run:

```bash
cd ../backend
php artisan accounting:sync-current-state
```

The sync is idempotent. Legacy inventory receipt movement journals are preserved and not duplicated.
