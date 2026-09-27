# YODAMEN 100K7N14
## System-wide Reversal Approval + Petty Cash / Expense / Navigation Refinements

### Scope
This update builds on the current N13/N13A/N13B line. It does not activate or modify the held 100K7O statutory contribution engine.

### Reversal approval control
Existing approval workflows remain authoritative for:
- Sales transaction correction/reversal requests
- Expense reversal requests
- Stock Received full reversal and item-level correction requests
- Manual Inventory Adjustment reversal requests

This update converts the remaining user-facing direct reversal actions to request -> approval/rejection -> posting:
- Petty Cash replenishment reversal
- Business Funding reversal
- Cash Remittance reversal
- Cash Deposit reversal
- Payroll Adjustment reversal

A generic auditable `reversal_approval_requests` record preserves requester, reason, source, status, reviewer, review notes and timestamps. The operational/financial reversal is not applied until approval succeeds. Duplicate pending requests for the same source are blocked.

### Petty Cash
- Reversal now requires approval.
- Pending reversal is visible in the ledger and approvers can Approve/Reject.
- Replenished excludes transactions that have been reversed.
- Disbursed excludes reversed source transactions and reversal journal rows.
- New Reversed summary card shows reversal value in the selected period.
- Five summary cards fit in one desktop row.
- Cash In, Cash Out and Balance headers/values are right aligned.

### Expense Log
- Pending reversal status is displayed directly as a `Pending Approval` badge.
- New Fund Source filter.
- Filter affects displayed rows and summary totals.

### Navigation
Sales:
1. Transaction
2. Retail POS
3. Collections
4. Cash Handling
5. Z-Reading
6. Dashboard

Accounting:
- Chart of Accounts
- Accounting Mapping
- General Ledger...

Accounting Mapping is removed from Admin navigation. Collections is removed from Accounting navigation.

### Permissions added
- petty-cash.APPROVE_REVERSAL
- cash-management.APPROVE_REVERSAL
- cash-deposits.APPROVE_REVERSAL
- payroll.APPROVE_REVERSAL

Existing Super Admin and Manager roles receive these approval permissions during migration. Fresh installations receive them through FoundationSeeder as well.

### Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14-SYSTEMWIDE-REVERSAL-APPROVAL-AND-FINANCE-UI.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/PettyCashSubledgerTest.php
php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Hr/PayrollTest.php
php artisan test tests/Feature/Hr/PayrollRecoveryTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php --filter="does not duplicate commission"
php artisan test

cd ../frontend
npm run build
```

### Important baseline note
Install N13B first if it has not yet been installed. N14 does not replace `PayrollRecoveryTest.php`, so the N13B payroll stale-timeline test correction remains intact.

### Hold
100K7O statutory payroll/contribution work remains ON HOLD.
