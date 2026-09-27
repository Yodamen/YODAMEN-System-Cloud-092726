# YODAMEN Update 100K4
## Management Accounting, Transaction State, Inventory and Financial Reporting UX

This package is cumulative from the current authoritative project state after Updates 100K2 through 100K3E.

### Main changes
- Approved Corrections return the active sale to Pending / FOR_CHECKOUT for fresh checkout finalization instead of prematurely classifying the corrected transaction as On Account.
- On Account uses Collect Payment; Checkout remains a Pending action.
- Servicing retail selection uses the SKU/product master and retail terminology is standardized.
- Expenses: Payee naming, mapped split payments, no redundant Payment Account selector, improved remove action, Encoded By column.
- Stock Received: mapped split payments, cleaner payment-method labels, tighter desktop one-row item entry, improved field sizing.
- Petty Cash branch loading repair and active accounting posting workflow.
- Commission Summary: Released Commission card excludes OPEN/unreleased commissions and follows the selected date range.
- Inventory stock statuses receive distinct visual treatments; Supplier Masterlist width/action alignment improved.
- Service Consumption preserves Booking ID for reversed/corrected source visits.
- Stock Adjustment shows Basic UOM automatically beside Quantity.
- General Ledger and Trial Balance show Debit/Credit totals and Balanced / Out of Balance status above the table.
- Cash Flow summary cards use Opening/Ending row plus Operating/Investing/Financing row.
- Financial Statements: Income Statement and Balance Sheet tabs; yearly Jan-Dec + Full Year Income Statement; PHP Amount and Common Size % views.
- Chart of Accounts gains Financial Statement Group. Groups are user-extensible under Reference Lists > Financial Statement Group.
- Existing standard COA codes receive sensible initial FS group mappings during migration.

### Installation
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K4-MANAGEMENT-ACCOUNTING-UX-FINANCIAL-REPORTING.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan test

cd ../frontend
npm run build
```

### Suggested focused checks before the full suite
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend

php artisan test tests/Feature/Sales/SalesCoreTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Inventory/InventorySupplierUomTest.php
php artisan test
```

### Manual verification checklist
1. Finalize a sale, submit a Correction, approve it as Manager, then confirm the surviving transaction returns to Pending and only Pending shows Checkout.
2. Finalize the corrected sale again and confirm Paid / On Account / Service Credit classification is assigned only after that checkout.
3. Create a paid Expense with two payment splits (for example BDO + GCash). Confirm the GL credits each mapped COA and no Payment Account field is required.
4. Receive stock with split payment and confirm Inventory / Freight-In debits balance against the mapped payment-method credits.
5. Open Petty Cash, post an entry, and confirm the Petty Cash account and counterpart account appear in the GL.
6. Check GL and Trial Balance totals/status; both should report Balanced where appropriate.
7. Open Financial Statements and review Income Statement by month in PHP Amount and Common Size % views.
8. Add/edit Financial Statement Group options from Reference Lists, assign them in Chart of Accounts, then confirm the yearly Income Statement grouping follows those assignments.

### Migration
This update includes:
- `2026_09_24_005000_expand_payment_splits_and_financial_statement_groups.php`

Run `php artisan migrate` before testing the new split-payment and FS-group fields.
