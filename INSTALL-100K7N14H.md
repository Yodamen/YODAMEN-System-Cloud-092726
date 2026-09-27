# YODAMEN Update 100K7N14H

## Scope
- Enforces one active reversal/correction approval request per original transaction/source.
- Closes the Stock Return loophole where multiple PENDING Stock Returns could be submitted against the same Stock Received receipt.
- Cross-protects Stock Return versus Stock Received correction/reversal workflows on the same receipt.
- Strengthens same-source locking for generic reversal approvals, Expenses, Manual Inventory Adjustment reversals, and Stock Received correction/reversal requests.
- Keeps Stock Return Branch, Status, From, To, Search, and Apply controls in one desktop row.

## Important behavior
- A second reversal/correction request is rejected while the first request for the same source transaction is still PENDING.
- After the pending request is rejected, a fresh request may be submitted if the source is otherwise eligible.
- Approved/posted history remains immutable and auditable.
- Stock Return source dropdown excludes receipts currently tied to a pending Stock Return or pending Stock Received correction/reversal.
- Existing Sales Transaction correction/refund/reversal protection remains unchanged because it already blocks another active request for the same sale.

## Database
No migration in this update.

## Install
From the project root:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14H-PENDING-ACTION-GUARD-STOCK-RETURN-FILTERS.zip -d .

cd backend
php artisan optimize:clear
```

## Targeted regression tests

```bash
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Accounting/PettyCashSubledgerTest.php
php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Hr/PayrollTest.php
```

Then run the full backend suite:

```bash
php artisan test
```

Build the frontend:

```bash
cd ../frontend
npm run build
```

## Held work
100K7O statutory payroll remains on hold and is not included or activated by this package.
