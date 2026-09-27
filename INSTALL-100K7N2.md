# YODAMEN 100K7N2 — Payment Mapping + Protected Fund Sources

This is a **non-payroll** update. It does not include or require the held 100K7O statutory contribution engine.

## What this consolidates and fixes

- Includes the Payment Method Mapping role UI from the earlier O1 patch without pulling in payroll changes.
- Includes the missing backend mapping-controller changes so Customer Payment and Operating Fund Source are truly independent.
- `POS / E-Wallet Clearing` can still be used as a customer-collection/clearing mapping, but it is forced OFF as an Operating Fund Source.
- `Owner's Capital` is forced OFF as an Operating Fund Source. Owner capital must enter through Accounting → Cash Management → Business Funding.
- `Loans Payable – Owner` is also protected for the same reason. Owner loans enter through Business Funding, then the actual bank/e-wallet balance funds operations.
- Cash on Hand and Cash with Manager remain protected from ordinary operating disbursements.
- Petty Cash remains a valid internal Operating Fund Source but cannot be customer-facing.
- Fresh installations seed these classifications correctly.
- Existing installations are normalized by migration.
- COA edit UI and Payment Method Mapping UI lock the protected fund-source checkboxes so users cannot accidentally re-enable them.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N2-PAYMENT-MAPPING-PROTECTED-FUND-SOURCES.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/AccountingPaymentMappingTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Important

This patch safely supersedes the earlier 100K7O1 Payment Method Mapping patch. It can be installed whether or not 100K7O1 was already applied. Laravel will skip migration `010000` if it already exists in the migrations table and will apply the new protection migration `011000`.

## Expected regression count

The accepted non-payroll baseline after 100K7N is 250 tests. This consolidated mapping line adds 4 tests total (2 from the earlier mapping-role work + 2 protection tests), so if 100K7O remains uninstalled as requested, the expected full suite is **254 passed**.
