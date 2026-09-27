# YODAMEN 100K7M3 — AP Funding Source Regression Fix

Purpose:
- Align Accounts Payable regression fixture with the new Expense / Supplier Funding Source control.
- Close the legacy Expense payment-method fallback so mapped payment methods must also resolve to an active POSTING account enabled as a funding source.
- Cash on Hand remains blocked from operational disbursements.

Install:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7M3-AP-FUNDING-SOURCE-REGRESSION-FIX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan test tests/Feature/Accounting/AccountsPayableTest.php
```

If targeted test passes:

```bash
php artisan test
```

No migration is required.
