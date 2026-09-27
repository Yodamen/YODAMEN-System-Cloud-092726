# YODAMEN Update 100K7L
## Opening Customer Balances + Active Packages

Baseline: intended for the current source after 100K7K (241 tests passing before this update).

### What this update adds

Admin > Opening Balances now supports the next cutover layer:

- Customer Balances: current receivables still collectible at go-live, without rebuilding old sales/bookings.
- Active Packages / Service Credits: current remaining sessions, remaining service value, optional outstanding customer balance, and optional expiry.
- Existing package history is not recreated. The opening package starts with the configured remaining entitlement.
- Opening package unused value is posted to Deferred Package Revenue.
- Opening customer balances are posted to Accounts Receivable.
- Opening Balance Equity remains the cutover balancing account.
- Accounts Receivable reporting includes opening receivables.
- Opening package balances appear in Service Balances.
- Opening receivables can be settled from the normal Booking / Walk-In payment collection workflow. The collection remains tied to the new Booking/Walk-In receipt.
- Payment allocations now support both existing On Account sale lines and Opening Balance receivables.
- Full Development Reset clears the new opening receivable subledger.

### Example

A 6-session Dermaflake package with 2 historical sessions already used can be entered as:

- Remaining Sessions: 4
- Remaining Service Value: 4,000
- Outstanding Balance: 3,000 (only if still collectible)

YODAMEN creates the 4 usable service credits immediately. It does not recreate the first 2 historical bookings/sessions.

### Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7L-OPENING-CUSTOMER-BALANCES-ACTIVE-PACKAGES.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
```

### Focused regression tests

```bash
php artisan test tests/Feature/Accounting/OpeningBalancesTest.php
php artisan test tests/Feature/Accounting/OpeningCustomerBalancesTest.php
php artisan test tests/Feature/Packages/PackageServiceCreditTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
```

Then run the full suite:

```bash
php artisan test
```

Frontend build:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

### Migration

This update includes:

`2026_09_25_006000_add_opening_customer_balances_and_packages.php`

Do not skip `php artisan migrate`.
