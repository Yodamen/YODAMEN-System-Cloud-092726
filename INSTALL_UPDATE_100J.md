# YODAMEN Update 100J — Expense Layout + Petty Cash + Accounting Core

## Included
- Replaces the cramped Expense Lines table with YODAMEN-style row cards.
- Activates Expense → Petty Cash.
- Activates Accounting → General Ledger.
- Activates Accounting → Trial Balance.
- Activates Accounting → Cash Management.
- Activates Accounting → Daily Closing.
- Activates Accounting → Financial Statements.
- Financial Statements supports Accrual/Cash basis and System/Audit-Adjusted COGS selectors.
- Adds 4901 Inventory Count Gain and 5004 Inventory Shrinkage / Count Variance.
- Petty Cash uses source-linked double-entry journals and append-only reversals.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100J-PETTY-CASH-ACCOUNTING-CORE.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan test

cd ../frontend
npm run build
```

If Vite is not running:

```bash
npm run dev
```

## Accounting coverage
The activated accounting reports read the posted accounting journal ledger. Expense, Petty Cash, and already-integrated accounting events appear immediately. The approved posting matrix still needs to be wired source-by-source for Sales, Payroll, Stock Received, Service Consumption, approved Audit Count variance, and the remaining operational events before Financial Statements become fully authoritative for the entire business.
