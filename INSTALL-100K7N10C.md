# YODAMEN 100K7N10C

## Scope
- Merge Customer Type Criteria and Customer Class Criteria into Customers > Customer Mix.
- Customer Mix tabs: Class and Type.
- Customer Type is fully configurable: add, rename, remove, activate/deactivate, and change visit thresholds.
- Existing New / Returnee / Regular / Loyal rows remain only as starter configuration; application logic no longer requires those names/codes/count.
- Customer Type is removed from Reference Lists as a duplicate configuration source.
- Promotion Rules now use Customer Mix type criteria for Customer Type targeting.
- Sales Dashboard financial row is four equal cards across desktop.
- Second KPI row: Ave Booking Value, Client Visits, Ave Lifetime Value, Unique Customers.
- All four customer KPI cards open monthly trend analytics.
- Sales Dashboard Customer Mix analytics use configured Customer Type names dynamically.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10C-CUSTOMER-MIX-SALES-KPI-TRENDS.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
```

No migration is required for 100K7N10C.

## Targeted tests
```bash
php artisan test tests/Feature/Customers/CustomerClassificationTest.php
php artisan test tests/Feature/Discounts/DiscountRuleTest.php
php artisan test tests/Feature/Sales/SalesCoreTest.php
```

Then full regression:
```bash
php artisan test
```

If 100K7N10B is clean, expected full test count remains 264 passed.

## Frontend build
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```
