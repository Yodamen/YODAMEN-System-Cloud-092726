# YODAMEN 100K7N8 — Electronic Tips Payable

This update tracks only staff tips received through a business-controlled bank, e-wallet, or card/POS payment method during checkout.

## Rules
- Cash tips are not tracked.
- Electronic tips are separated from sale revenue and posted to Staff Tips Payable.
- Tips may be released from Petty Cash or an approved Operating Fund Source bank/e-wallet.
- Cash on Hand, Cash with Manager, and POS/E-Wallet Clearing cannot be used as tip payout sources.
- Tip payouts do not reduce Cash Handling cash-sales expectations.
- Sales reporting, Z-Reading payment totals, and Accounting Collections exclude TIP payment rows from business-sales collections.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N8-ELECTRONIC-TIPS-PAYABLE.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
php artisan test tests/Feature/Sales/StaffTipWorkflowTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

Expected non-payroll suite count after this update: 260 tests, assuming the prior 100K7N7 baseline is clean at 257.
