# YODAMEN 100K7N4 — Cash Handling + Inventory/Services Filter Layout

This is a non-payroll update and assumes the accepted non-payroll line through 100K7N3.

## Included
- Cash Handling list tables now show the logged-in user who remitted or deposited cash.
- Inventory Dashboard SKU Name filter widened on desktop.
- Inventory Masterlist Item Name filter widened on desktop.
- Stock Received adds Supplier Name and Item Name filters; Branch filter is slimmer; backend supports both filters.
- Services & Pricing adds a Service Name filter and keeps all desktop filters/actions in one row with a slimmer Service Count field.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N4-INVENTORY-SERVICE-CASH-HANDLING-UI.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
```

No migration is required.

## Targeted regression
```bash
php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
```

Then:
```bash
php artisan test
```

The patch modifies existing tests only, so the test-count baseline does not increase.

## Frontend
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```
