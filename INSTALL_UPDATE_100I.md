# YODAMEN Update 100I — Chart of Accounts + Expenses

## Scope
Activates:
- Accounting → Chart of Accounts
- Expense → Expense Log

This update also strengthens the existing accounting journal foundation by adding:
- `branch_id` to accounting journal headers
- `account_id` linkage from journal lines to the Chart of Accounts

## COA cleanup incorporated
The supplied YODAMEN COA is preserved with these cleanups/improvements:
- Duplicate `1102` merged as **Staff Cash Advances**
- Duplicate `2006` merged as **Staff Tips Payable**
- Added `1004 Petty Cash`
- Added `5201 Retail Product Cost of Sales`
- Added `6308 Bank & Merchant Fees`
- Added `6312 Interest Expense`
- Added `6313 Cash Advance Write-Off Expense`
- Clarified `6203` as **Marketing & Advertising Professional Fees**
- `3201 Current Year Net Income` is system-only
- OPEX and cash-flow classifications are stored per account

The seed is idempotent: rerunning it does not duplicate or overwrite existing account edits.

## Expense workflow
Draft → Posted → Reversed

Payment status is separate:
- Paid at posting: expense lines are debited and the selected cash/bank/e-wallet account is credited.
- Unpaid at posting: expense lines are debited and `2001 Accounts Payable – Suppliers` is credited.
- Mark Paid later: Accounts Payable is debited and the selected payment account is credited.
- Reverse: original expense stays in history and a reversing journal is posted.

Posted expenses cannot be directly edited.

## Expense entry features
- Date and branch
- Vendor / payee
- Reference / OR / invoice
- Multi-line expense/account classification
- Paid or Unpaid/Payable
- Payment account and method
- Notes
- Optional PDF/image supporting document
- Automatic balanced double-entry journal
- Protected journal detail view

Tracked inventory accounts are intentionally excluded from manual Expense line selection. Inventory purchases should continue through Inventory → Stock Received so stock and accounting can later be integrated without duplicate entries.

## Install
From Terminal:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100I-CHART-OF-ACCOUNTS-EXPENSES.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=ChartOfAccountsSeeder

php artisan test --filter=ExpenseModuleTest
php artisan test
```

If backend tests pass:

```bash
cd ../frontend
npm run build
```

If the Vite dev server is not already running:

```bash
npm run dev
```

If it is already running, hard-refresh the browser with Command + Shift + R.

## Verification
1. Open Accounting → Chart of Accounts and confirm the seeded accounts are visible.
2. Open Expense → Expense Log.
3. Create a Draft paid expense and add at least one expense line.
4. Post the expense and open View Details. Confirm the journal debits the selected expense account and credits the selected payment account.
5. Create an Unpaid expense and Post it. Confirm the journal credits 2001 Accounts Payable – Suppliers.
6. Use Mark Paid. Confirm the second journal debits 2001 and credits the selected payment account.
7. Reverse a posted expense. Confirm the original remains visible as REVERSED and a reversing journal appears in View Details.

## Notes
- No destructive deletion is added for posted expenses or used COA accounts.
- Manager and Super Admin receive the new Expense and COA permissions.
- Expense supporting documents use Laravel local protected storage and are served through authenticated endpoints; `storage:link` is not required.
