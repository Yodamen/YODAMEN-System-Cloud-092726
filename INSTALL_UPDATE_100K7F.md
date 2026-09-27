# YODAMEN Update 100K7F

## Scope

This consolidated update includes the requested UI cleanup and payroll funding split enhancement:

- Development Reset:
  - removes the requested helper/warning badges and descriptive paragraphs
  - keeps Customer Tests Only and Full Reset cards equal in desktop width/height
- Petty Cash:
  - top action is now one palette-colored `Replenish` dropdown
  - dropdown actions are `Replenish` and `Reconcile`
  - Reconcile modal aligns Actual Cash Count and Difference as equal panels
  - UI wording uses Reconciliation consistently
- Sales Transactions:
  - `Reversal` action button is renamed `Reverse`
  - button uses the standard yellow primary palette
- Payroll Mark Paid:
  - requires allocation of Net Pay across actual business funding sources
  - supports 1 to 5 distinct funding accounts
  - each line stores Funding Source, Amount, and optional Reference
  - total funding must equal Net Pay before the run can be marked Paid
  - duplicate funding accounts are blocked
  - legacy single-source API calls remain supported
  - split funding is persisted for audit and shown on the Payroll Run
  - accounting payment journal credits each actual funding account separately
  - Petty Cash-funded payroll portions automatically appear in the Petty Cash subledger
  - any Chart of Account enabled as an Expense / Supplier Funding Source can be used, including owner-funded accounts configured that way
- Development Reset includes the new payroll payment split rows in Full Reset cleanup.

## Migration

This update contains:

`2026_09_25_004000_add_payroll_payment_splits.php`

The migration creates `payroll_payment_splits` for durable, auditable split payroll funding.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7F-UI-PAYROLL-SPLIT-FUNDING.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
```

## Focused tests

```bash
php artisan test tests/Feature/Hr/PayrollTest.php
php artisan test tests/Feature/Foundation/DevelopmentResetServiceTest.php
php artisan test tests/Feature/Accounting/PettyCashSubledgerTest.php
```

Then run:

```bash
php artisan test
```

Build the frontend:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Payroll funding example

For an `₱18,000` Net Pay, Mark Paid can be allocated as:

- BDO: `₱10,000`
- GCash: `₱3,000`
- Petty Cash: `₱2,000`
- Owner-funded account: `₱3,000`

The allocation must total exactly `₱18,000`.

The embedded accounting payment journal settles the payroll liabilities and credits each selected funding account for its actual amount. Petty Cash only receives the portion actually funded from Petty Cash.

## Compatibility

Existing paid payroll runs that only have `payment_account_id` remain readable. The accounting posting service falls back to the historical single-source fields when a run has no split rows.
