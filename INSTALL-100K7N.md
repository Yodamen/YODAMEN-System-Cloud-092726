# YODAMEN 100K7N — Cash Handling: Remit + Deposit + Calendar

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N-CASH-HANDLING-REMIT-DEPOSIT-CALENDAR.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Functional changes

- Sales navigation now uses **Cash Handling** below Z-Reading.
- Main process tabs: **Remit** and **Deposit**.
- View tabs: **List** and **Calendar**.
- Remit moves physical cash from Cash on Hand to **Cash with Manager / Undeposited Cash**.
- Deposit supports:
  - direct Cash on Hand → Bank/E-Wallet;
  - remitted cash → Bank/E-Wallet.
- Calendar statuses:
  - Pending
  - Partial
  - Remitted
  - Deposited
  - No Cash
- Existing `/sales/cash-deposits` and `/accounting/cash-deposits` frontend URLs redirect to `/sales/cash-handling`.
- Existing cash-deposit API endpoints are preserved for compatibility.
- Cash with Manager is a cash asset for balance-sheet/cash-management purposes, but cannot be used as a customer payment mapping or operational funding source.
- Full Development Reset includes cash remittances in FK-safe order.
