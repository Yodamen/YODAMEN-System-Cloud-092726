# YODAMEN 100K7N14N

## Scope

1. Mandatory payment proof for non-cash payments
   - Reservation Booking advance/interim receipt
   - Walk-In advance/interim receipt
   - Sales Checkout
   - Retail POS Checkout
   - Cash-only payments remain exempt.
   - Any mixed/split payment containing a non-cash method requires proof.
   - Uses the existing private Supporting Document framework.
   - Accepts the framework formats: PDF, JPG/JPEG, PNG, WEBP, DOC/DOCX, XLS/XLSX, CSV.

2. Booking supporting-document permission
   - Adds `bookings.DOWNLOAD` to User & Role Management.
   - Existing roles with `bookings.VIEW`, plus Super Admin roles, receive it on migration so existing access is not unexpectedly broken.
   - It can then be removed per role as needed.

3. Stock Returns filter bar
   - Branch, Status, From, To, Search, Apply remain on one desktop row.
   - Compact widths patterned after Stock Received.

4. Services & Pricing filter bar
   - Search, Service Name, Category, Service Count, Status, Reset remain on one desktop row.
   - Compact Stock Received-style field sizing.

5. AR/AP summary cards
   - Restyled to the Cash & Bank / Cash Flow card language.
   - AR keeps all aging buckets including 31–60 days.
   - AP keeps ledger reconciliation status and all aging buckets including 31–60 days.
   - Desktop remains a single summary row; responsive layouts collapse below desktop widths.

## Manual Inventory Adjustment accounting rule (existing behavior, unchanged)

On approval, the system posts the approved quantity change using the item unit-cost snapshot:

- Positive adjustment / surplus:
  - Dr Inventory Asset
  - Cr Inventory Count Gain

- Negative adjustment / shortage:
  - Dr Inventory Count Variance
  - Cr Inventory Asset

An approved adjustment reversal posts the exact opposite of the original gain/variance effect.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14N-NONCASH-PAYMENT-PROOF-AND-UI-REFINEMENTS.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
```

## Targeted tests

```bash
php artisan test tests/Feature/Foundation/DownloadPermissionsTest.php
php artisan test tests/Feature/Operations/BookingTest.php
php artisan test tests/Feature/Operations/QueueTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Sales/RetailPosTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Accounting/AccountsReceivableTest.php
php artisan test tests/Feature/Accounting/AccountsPayableTest.php
```

If a named accounting test file is not present in the local repository, skip that single command and run the full suite below.

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Notes

- This update requires a migration only for the new `bookings.DOWNLOAD` permission.
- The payment-proof attachment schema itself comes from the previously consolidated Supporting Document framework.
- 100K7O statutory payroll remains on hold and is not included.
