# YODAMEN Update 100K7E — Petty Cash Subledger & Liquidation

## What changed
- Petty Cash is no longer a duplicate expense-entry module.
- New manual actions are limited to **Replenish Petty Cash** and **Liquidate / Reconcile**.
- The Petty Cash ledger is now derived directly from posted accounting journal lines that hit the Petty Cash COA.
- Posted Expenses, Accounts Payable payments, Stock Received/supplier payments, Payroll, and other accounting events automatically appear whenever Petty Cash is the funding account.
- Liquidation records the book balance versus actual physical cash count without re-posting the underlying expenses.
- Short/over liquidations are recorded as reconciliation variances only; they do not alter the ledger automatically.
- Payroll Mark Paid now asks for a Funding Source. Choosing Petty Cash credits Petty Cash and makes the payroll payment appear automatically in the Petty Cash ledger.
- Existing historical manual petty cash entries remain visible for audit history; new direct disbursement/opening/adjustment entries are blocked.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7E-PETTY-CASH-SUBLEDGER-LIQUIDATION.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
```

## Focused tests
```bash
php artisan test tests/Feature/Accounting/PettyCashSubledgerTest.php
php artisan test tests/Feature/Accounting/AccountsPayableTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Hr/PayrollTest.php
```

Then:
```bash
php artisan test
```

Frontend:
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Accounting behavior
### Replenishment
DR Petty Cash / CR selected Funding Source.

### Expense/AP/Stock/Payroll paid from Petty Cash
The originating module posts the cash-out. Petty Cash only reads the resulting journal; no duplicate entry is required.

### Liquidation
Liquidation is a reconciliation record only. It stores Book Balance, Actual Cash Count, Variance, period covered, and status (BALANCED / SHORT / OVER). It does not recognize the expense again and does not auto-post a shortage/overage adjustment.

## Migration
Yes. `2026_09_25_003000_redesign_petty_cash_and_payroll_funding.php` must be run.
