# YODAMEN 100K7N10G — Sidebar Order + Customer Badge Propagation

## Changes
- Sidebar: Customers now appears before Operations.
- Sales submenu order: Transaction, Retail POS, Z-Reading, Dashboard, Cash Handling.
- Customer badge identity now propagates to Sales Transactions including Paid/On Account/Service Credit/Reversed settlement tabs.
- Customer badge identity now propagates to Retail POS history and Servicing.
- Badge text remains Customer Class; badge color remains Customer Type color.
- Legacy `segment` remains in API responses for backward compatibility, but new customer identity fields are also returned.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10G-SIDEBAR-BADGE-PROPAGATION.zip -d .
cd backend
php artisan optimize:clear
```

No migration is required.

## Targeted tests
```bash
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Sales/RetailPosTest.php
php artisan test tests/Feature/Operations/ServicingTest.php
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
