# YODAMEN 100K7N11 — Inventory + Expense Reversal Controls / Stock Returns

This update is on the **non-payroll development line**. It does not include the held 100K7O statutory contribution engine.

## Included

- Manual Inventory Adjustment reversal requests + approval.
- Stock Received full reversal requests + separate approval permission.
- Stock Received item-level correction requests + separate approval permission.
- Paid downward receipt corrections create Supplier Refunds Receivable and can record the later bank/e-wallet refund.
- New Inventory > Stock Returns module.
  - Approval before inventory/accounting effects.
  - Unpaid returns reduce Accounts Payable.
  - Paid returns create Supplier Refunds Receivable.
  - Supplier refunds can be received only into actual bank/e-wallet cash accounts, not Cash on Hand, Petty Cash, Cash with Manager, or POS/E-Wallet Clearing.
- Expense reversals now follow Request > Approve/Reject > Post reversal.
- Original records/journals remain preserved for audit.
- AP outstanding incorporates approved Stock Return credits.
- Development reset and permissions updated.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N11-INVENTORY-EXPENSE-REVERSAL-STOCK-RETURNS.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"

php artisan optimize:clear
php artisan migrate
```

## Targeted tests

```bash
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
```

Then full regression:

```bash
php artisan test
```

This update adds **6 new test cases**. If the accepted pre-update baseline is 264 tests, the expected total is **270 passed**.

## Frontend build

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Accounting notes

- Full Stock Received reversal is for an erroneous receipt and reverses its net receipt/payment/correction accounting after approval.
- A real physical return to a supplier should use **Inventory > Stock Returns** instead.
- Item-level corrections preserve the original receipt and post only the approved delta.
- Manual Inventory Adjustment reversal preserves the original adjustment and posts an opposite linked inventory movement.
- Expense reversal preserves the posted expense and creates a separate reversal journal only after approval.
