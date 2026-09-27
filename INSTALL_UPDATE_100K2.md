# YODAMEN Update 100K2 — Accounting Regression Stabilization

## Baseline
This update is cumulative from the authoritative September 24, 2026 handoff source. Do not rebuild from older update ZIPs.

## What was verified first
The authoritative handoff source already contains `AccountingJournalService::accountByKey()`, which means it is newer than the 65-failure test snapshot that reported the method as missing. Update 100K2 keeps automatic accounting active and hardens that lookup instead of disabling listeners or accounting events.

The uploaded SQL dump stops at migration `2026_09_24_002000_activate_petty_cash_and_accounting_core`. Migration `2026_09_24_003000_activate_operational_accounting_and_remaining_modules` is therefore still pending in that database snapshot and must be applied.

## Fixes included

### 1. Accounting journal account lookup hardened
`AccountingJournalService::accountByKey()` now:
- normalizes system keys,
- ignores blank keys,
- returns only active posting accounts.

### 2. Fresh-install/test COA regression fixed
`ChartOfAccountsSeeder` now includes all system accounts required by automatic operational accounting, including:
- Accounts Receivable — Customers
- Customer Advances / Unapplied Receipts
- Deferred Package Revenue
- Deferred Membership Revenue
- Profit Sharing Payable
- Partner Profit Distributions
- Sales Discounts & Allowances
- Sales Returns & Refunds
- Inventory Count Gain
- Inventory Shrinkage / Count Variance
- Other Service Commissions
- Retail / Sales Commissions
- salary system mappings for Barbers, Therapists, and Admin

This matters because migrations run before `FoundationSeeder` in `RefreshDatabase`; migrations 002/003 cannot populate business-specific COA rows when the business does not exist yet.

### 3. Fresh-install accounting permissions aligned
`FoundationSeeder` now creates and grants the same active accounting permissions expected by migrations 002/003, including:
- Petty Cash create/reverse
- General Ledger view
- Trial Balance view
- Daily Closing close/reopen
- Bank Reconciliation create/edit/reconcile
- Accounting Mapping edit

`my-profit.VIEW` is also granted to every seeded role, matching the operational-accounting migration's self-service rule.

### 4. Regression coverage added
- The advance-payment SalesCore test now asserts that booking advance collection creates a balanced automatic journal:
  - DR Cash on Hand
  - CR Customer Advances / Unapplied Receipts
- New `OperationalAccountingFoundationTest` verifies required automatic-posting system accounts and accounting permissions on a fresh database.

### 5. Current handoff UI/status verified
No frontend files are changed in 100K2 because the authoritative source already contains the two pending Service Consumption requests:
- Booking ID is the Consumption History headline and Consumption ID is the subheadline.
- Service Material Recipe modal is widened for a one-row desktop material layout.

The remaining accounting pages are also already routed/enabled in the current source. This update fixes fresh-seed access consistency rather than replacing those pages.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K2-ACCOUNTING-REGRESSION-STABILIZATION.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
```

### Run the new accounting-foundation regression first

```bash
php artisan test tests/Feature/Accounting/OperationalAccountingFoundationTest.php
```

### Then run the SalesCore regression that originally cascaded

```bash
php artisan test tests/Feature/Sales/SalesCoreTest.php
```

### Then run the full backend suite

```bash
php artisan test
```

If the full suite is green, backfill the current development database into the journal:

```bash
php artisan accounting:sync-current-state
```

The sync is idempotent by accounting source key. It intentionally represents existing corrected sales using their current state instead of replaying every historical correction delta on top of that current state.

## Important
- Do not remove or disable the automatic accounting model listeners.
- Do not run old update ZIPs over the current source.
- Do not run `db:fresh` against the current development database.
- Posted journals remain source-linked and idempotent.
- Existing finalized accounting history should be corrected through reversal/adjustment, not destructive edits.

## Sandbox validation performed while building this package
The handoff intentionally excludes `backend/vendor`, so Laravel/Pest could not execute in the packaging sandbox. The attempted focused Artisan test stops at the missing `vendor/autoload.php` before Laravel boots. All 347 PHP files under `app`, `database`, `tests`, and `routes` passed `php -l` syntax validation after the changes.
