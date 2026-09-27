# YODAMEN 100K7O1 — Payment Method Role Mapping + Layout Fix

## What this update changes

- Renames **Customer Payment Method Mapping** to **Payment Method Mapping**.
- Adds two independent role controls per mapped cash/bank/e-wallet account:
  - **Customer Payment** — whether the method may appear in Booking, Checkout, POS, and other customer collection flows.
  - **Operating Fund Source** — whether the mapped COA account may be used by Expenses, AP, Payroll, supplier settlement, and similar operating disbursements.
- The Operating Fund Source checkbox updates the mapped account's existing `is_funding_source` COA flag. No duplicate fund-source field is created.
- Petty Cash can now be mapped in Payment Method Mapping as an internal operating source, but is forced **off** for Customer Payment.
- Cash on Hand remains protected from ordinary operating disbursements.
- Cash with Manager remains controlled by Cash Handling and cannot be mapped as a payment method.
- Existing mappings to Petty Cash / Cash with Manager are normalized so they do not appear as customer payment methods.
- Renames the COA form label **Expense / Supplier Funding Source** to **Operating Fund Source** for consistency.
- Reworks Payment Method Mapping table widths, checkbox sizing, headings, and responsive overflow to prevent overlapping / awkward wrapping on desktop.
- Adds two automated regression tests for independent collection/disbursement roles and Petty Cash protection.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7O1-PAYMENT-METHOD-ROLE-MAPPING-UI-FIX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/AccountingPaymentMappingTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Expected configuration examples

| Account / Method | Customer Payment | Operating Fund Source |
| --- | --- | --- |
| Cash on Hand | Yes | No (protected) |
| BDO Collections | Yes | No |
| BDO Disbursement | No | Yes |
| GCash used for both | Yes | Yes |
| Petty Cash | No (forced internal) | Yes |
| Cash with Manager | Not mappable | Not mappable |

## Regression note

This patch adds 2 tests. If the accepted baseline after 100K7O is 255 passed, the expected full-suite count becomes 257 passed.
