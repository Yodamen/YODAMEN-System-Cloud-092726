# YODAMEN Update 100K5 — Petty Cash Activation + Accounts Payable

## What this update fixes

- Repairs Petty Cash account loading. The controller was querying a non-existent/legacy `status=ACTIVE` COA field instead of the current boolean `active` field.
- Allows manual/direct-posting COAs such as Prepaid Rent to appear as Petty Cash counterpart accounts. SQL `NULL != PETTY_CASH` had been excluding accounts with no `system_key`.
- Adds an Accounts Payable module under Accounting.
- Accounts Payable combines unpaid Posted Expenses and unpaid Posted Stock Receipts.
- Adds AP aging: Current, 1–30, 31–60, 61–90, Over 90.
- Shows operational AP total alongside the actual 2001 Accounts Payable ledger balance and warns when they differ.
- Adds full settlement of Expense and Stock Receipt payables using one or more mapped Payment Methods.
- Stock Receipt AP settlement posts: DR Accounts Payable / CR mapped cash-bank-e-wallet accounts.
- Adds clean-install `ap.VIEW` and `ap.PAY` permissions for Super Admin and Manager.
- Adds regression coverage for Petty Cash, Accounts Payable, and Prepaid Rent reporting.
- Chart of Accounts now labels system accounts versus manual/direct-posting accounts more clearly.

## Prepaid Rent behavior

`1301 Prepaid Rent` is a Balance Sheet asset and a manual/direct-posting account. It does not belong in the Income Statement when initially recorded.

A paid posted entry such as:

- DR 1301 Prepaid Rent
- CR Cash / Bank

should appear in:

- General Ledger
- Trial Balance
- Balance Sheet under Assets
- Cash Flow Statement as a cash outflow when paid

It does not affect Rent Expense until a later amortization/reclassification entry moves the applicable amount from Prepaid Rent to Rent Expense.

Creating an Expense leaves it in DRAFT first. The accounting reports update only after the Expense is Posted.

## Installation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K5-PETTY-CASH-ACCOUNTS-PAYABLE.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/AccountsPayableTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Accounting/OperationalAccountingFoundationTest.php
php artisan test

cd ../frontend
npm run build
```

## After installation

- Expense → Petty Cash should load branches and counterpart accounts and allow posting.
- Accounting → Accounts Payable should appear for Super Admin and Manager.
- Unpaid Posted Expenses and unpaid Posted Stock Receipts should appear automatically in AP.
- Paying an AP item requires mapped Payment Methods from Admin → Accounting Mapping.

## Runtime note

The handoff environment does not include `backend/vendor` or `frontend/node_modules`, so Laravel/Pest and the React build could not be executed here. All changed PHP files passed `php -l` syntax validation.
