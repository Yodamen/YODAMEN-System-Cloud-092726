# YODAMEN Update 100K4C — Servicing SKU Validation Fix

## Fix
The Complete Service and Complete Selected Services request validators did not declare `retail_items.*.retail_product_id`. Laravel therefore removed the submitted Product Master ID from `validated()` before `VisitService` processed the retail purchase, causing completion requests with valid SKU-linked retail items to fail with HTTP 422.

This update:
- requires and preserves `retail_product_id` on both completion endpoints;
- keeps retail purchases linked to the Product Master/SKU;
- makes `item_name` optional because the authoritative product name is derived from the selected Retail Product;
- does not weaken stock, personnel, or inventory controls.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100K4C-SERVICING-SKU-VALIDATION-FIX.zip -d .
cd backend
php artisan optimize:clear
php artisan test tests/Feature/Operations/ServicingTest.php
```

If Servicing passes:
```bash
php artisan test
```

No migration is included.
