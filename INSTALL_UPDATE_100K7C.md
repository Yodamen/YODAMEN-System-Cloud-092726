# YODAMEN Update 100K7C

## Included
- Stock Received: keeps Remove on the same desktop row immediately after Total Cost and tightens all item columns to fit the wide desktop modal.
- Summary cards: compacts accounting KPI cards, Cash Flow summary strips, Accounts Receivable / Accounts Payable summaries, Commission Summary cards, and Inventory Dashboard cards.
- Latest-first transaction logs: General Ledger and Cash Flow now return newest posted journals first; Accounts Receivable and Accounts Payable return newest documents first; customer payment history and transaction payment/application history are newest-first as well.
- Existing modules already newest-first (Sales transaction tabs, Expenses, Petty Cash, Collections, Bank Reconciliation, Profit Sharing, Stock Received, Inventory Movements, Inventory Adjustments, Audit Counts, Service Consumption, Commissions, Cash Advances, Payroll) are preserved.
- Adds accounting report regression coverage for newest-first ordering.
- Includes the 100K7A payment-mapping test fixture alignment so this package is safe even if 100K7A was not installed separately.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7C-UI-LATEST-FIRST-LOGS.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No migration is required.
