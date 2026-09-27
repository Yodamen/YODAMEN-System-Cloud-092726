# YODAMEN Update 100K7A — Payment Mapping Test Alignment

## Why 44 tests failed after 100K7
100K7 intentionally requires customer-facing payment methods to be configured in Accounting Mapping. Existing regression fixtures created Cash/GCash reference values but did not create their AccountingPaymentMethodMapping rows, so the application correctly rejected them with HTTP 422. One newly added separation test also called the booking bootstrap route without the `/operations` prefix, causing the single HTTP 404.

## Fix
- Adds a reusable test helper `mapCustomerPaymentMethod()`.
- Maps fixture Cash to `CASH_ON_HAND`.
- Maps fixture GCash to `POS_CLEARING`.
- Updates package, checkout, retail booking, Retail POS, and sales core fixtures to reflect the 100K7 payment architecture.
- Corrects the FundingSourceSeparationTest booking bootstrap URL to `/api/v1/operations/bookings/bootstrap`.

## Production behavior remains strict
This update does not relax the 100K7 rule. Customer payment methods still require an active customer-facing Accounting Mapping. Internal funding sources such as Owner's Capital remain excluded from customer checkout.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7A-PAYMENT-MAPPING-TEST-ALIGNMENT.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
```

No migration is required.

## Focused tests
```bash
php artisan test tests/Feature/Accounting/FundingSourceSeparationTest.php
php artisan test tests/Feature/Packages/PackageServiceCreditTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Sales/RetailBookingInventoryRegressionTest.php
php artisan test tests/Feature/Sales/RetailPosTest.php
php artisan test tests/Feature/Sales/SalesCoreTest.php
```

Then run:
```bash
php artisan test
```
