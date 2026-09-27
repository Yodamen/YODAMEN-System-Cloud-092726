# YODAMEN Update 63 — Retail Commission Test Alignment

## Purpose
Update the legacy retail commission regression assertion after Update 62 introduced SKU/product-aware retail rule specificity.

## Change
- `Employee + Retail Purchases` -> `Employee + All Retail Products`

No production application logic, database schema, migration, routes, or frontend files are changed.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-7A-RETAIL-COMMISSION-TEST-ALIGNMENT-63.zip -d .
cd backend
php artisan test --filter=CommissionRuleTest
php artisan test --filter=RetailPosTest
```

No migration is required.
