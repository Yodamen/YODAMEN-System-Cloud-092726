# YODAMEN Update 100K7I — Opening Balance / Hard Cutover Setup

## Purpose
Adds a controlled Super Admin cutover workflow for an already-operating business that is starting the YODAMEN ledger midstream.

### Cash Management
- Adds **Add Balance** at the upper right for Super Admin.
- The button is a shortcut to **Admin → Opening Balances**.
- Cash Management itself remains ledger-derived/read-only.

### Admin → Opening Balances
One-time per branch cutover setup with:
- Cutover Date
- Cash & Banks tab for Cash on Hand, bank accounts, e-wallets and Petty Cash
- Beginning Inventory tab using Basic UOM quantity and Basic UOM cost
- Branded confirmation before posting
- Locked read-only summary after posting

Opening balances are only allowed before operational journals or inventory movements exist for the branch. For a hard restart, use Full Development Reset first, then post the opening balances before normal operations resume.

### Accounting behavior
Beginning balances **do create one opening balance journal** so the General Ledger, Trial Balance and Balance Sheet remain correct. They do not create sales, purchases, COGS or operating expenses.

Example beginning bank balance:
- DR Cash in Bank — BDO
- CR 3999 Opening Balance Equity

Beginning inventory:
- DR mapped Inventory Asset account
- CR 3999 Opening Balance Equity

The opening journal uses `source_type = OPENING_BALANCE`.

### Cash Flow behavior
Opening balance journals are excluded from Operating / Investing / Financing movement rows. Opening cash is treated as the starting balance even when the selected cash-flow range includes the cutover date.

### Inventory behavior
Beginning inventory:
- sets the starting `inventory_stocks.on_hand`
- creates an `OPENING_BALANCE` inventory movement for audit trail
- establishes the product starting Basic UOM cost
- posts the inventory asset value through the single opening journal
- does **not** post COGS, inventory variance, AP, or a Stock Received transaction

### New account
`3999 · Opening Balance Equity` is added as an Equity / NON_CASH posting account and is used only as the cutover clearing/equity counterpart.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7I-OPENING-BALANCE-CUTOVER.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
```

Focused tests:
```bash
php artisan test tests/Feature/Accounting/OpeningBalancesTest.php
php artisan test tests/Feature/Accounting/OperationalAccountingFoundationTest.php
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
```

Then:
```bash
php artisan test
```

Frontend:
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Recommended hard-restart sequence
1. Complete the approved Full Development Reset if existing system transactional/test data must be cleared.
2. Verify Chart of Accounts and Accounting Mapping.
3. Open **Admin → Opening Balances**.
4. Select the branch and cutover date.
5. Enter actual cash/bank/e-wallet/Petty Cash balances.
6. Enter actual counted inventory in Basic UOM plus Basic UOM cost.
7. Post Opening Balances once.
8. Verify Cash Management, Trial Balance, General Ledger, Balance Sheet, and Inventory Dashboard.
9. Resume normal bookings, sales, expenses, stock receipts, payroll, and other operations.

No operational P&L activity is created by this cutover.
