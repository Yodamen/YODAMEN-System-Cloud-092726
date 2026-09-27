# YODAMEN Update 100K3 — Payment Mapping + Development Reset

## Purpose

This cumulative update continues from the authoritative current source and stabilizes the next accounting/admin workflow items:

1. User-created bank and e-wallet COA accounts marked **Cash / Payment Account** can now be selected in **Admin → Accounting Mapping**.
2. Operational payment posting validates that a mapped cash account belongs to the same business and is active/cash-enabled.
3. Adds a **Development Reset** page under Admin, visible only to Super Admin accounts.
4. Development Reset supports:
   - **Customer Tests Only** — removes test-customer operational/accounting data while restoring inventory effects tied to those test transactions.
   - **Full Reset** — clears development transaction/history data while preserving configuration/master setup such as COA and accounting mappings.
5. Development Reset is restricted to `local` / `development` environments and requires Super Admin access-code confirmation plus an exact confirmation phrase.
6. Adds regression coverage for custom payment-account mappings, Super Admin reset access, and the sale → AR/revenue → payment → application accounting lifecycle.

## Important accounting behavior

- Paid Expenses already use any active COA flagged **Cash / Payment Account** as a selectable Payment Account. Your user-created `Cash in Bank - BDO` and `E-wallet / POS Clearing - Gcash` will therefore be available there when that flag is enabled.
- Accounting Mapping now uses the same `is_cash_account` rule, so custom banks/e-wallets are no longer excluded merely because they do not have a built-in `system_key`.
- Current source intentionally separates **operational completion** from **financial finalization**. A completed visit creates/updates a pending sale for checkout. Revenue and AR are posted when the sale becomes financially finalized. Collection then posts to the mapped cash account and the payment application clears AR/customer advances as applicable.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K3-PAYMENT-MAPPING-DEVRESET.zip -d .

cd backend
php artisan optimize:clear

php artisan test tests/Feature/Accounting/AccountingPaymentMappingTest.php
php artisan test tests/Feature/Admin/DevelopmentResetAdministrationTest.php
php artisan test tests/Feature/Foundation/DevelopmentResetServiceTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test

cd ../frontend
npm run build
```

No migration is included in Update 100K3.

## After installation

### Map your collection accounts
Go to **Admin → Accounting Mapping → Payment Method Mapping**.

Map each payment method to its actual receiving COA, for example:

- BDO / Bank Transfer → `Cash in Bank - BDO`
- GCash → `E-wallet / POS Clearing - Gcash`
- Cash → the appropriate Cash on Hand account

If BDO and GCash are currently represented by one generic payment method, create/use distinct Payment Method reference values first so each one can route to its own COA.

### Expenses
On a Paid Expense, **Payment Account** will list active COAs flagged **Cash / Payment Account**, including BDO and GCash. The accounting entry will credit the selected account.

### Development Reset
Go to **Admin → Development Reset** while signed in as a Super Admin.

- Prefer **Customer Tests Only** during transaction testing.
- Use **Full Reset** only when you intentionally want to clear development transaction/history data.
- The feature will not execute outside a local/development environment.

## Verification expected

A fully paid service checkout should show three linked journal events:

1. Sale finalization: DR Accounts Receivable / CR Service Revenue.
2. Payment receipt: DR mapped Cash/Bank/E-wallet / CR Customer Advances.
3. Payment application: DR Customer Advances / CR Accounts Receivable.

The net result is recognized revenue, the actual cash destination increased, and zero AR when the sale is fully paid.
