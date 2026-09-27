# 100K7N14P1 — Standardized Reason / Payment Proof Regression Fix

This patch addresses the six failures reported after 100K7N14P.

## Changes
- Updates legacy Inventory Approval tests to send the now-required standardized `reason_reference_id`.
- Fixes cash/non-cash classification for payment-proof enforcement: the Accounting Payment Method Mapping is authoritative, so a custom payment method mapped to `CASH_ON_HAND` is treated as cash even if its code/display name is custom.
- Keeps bank/e-wallet/card methods non-cash and therefore subject to mandatory payment proof.
- Updates electronic Staff Tip checkout fixtures to attach payment proof because the customer checkout itself is paid through GCash.
- Adds explicit regression coverage that a custom cash method mapped to `CASH_ON_HAND` does not require payment proof.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14P1-REGRESSION-FIX.zip -d .
cd backend
php artisan optimize:clear
```

No migration is required.

## Targeted tests
```bash
php artisan test tests/Feature/Inventory/InventoryApprovalAndRecipeTest.php
php artisan test tests/Feature/Sales/RetailBookingInventoryRegressionTest.php
php artisan test tests/Feature/Sales/StaffTipWorkflowTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php --filter="payment proof|custom payment method mapped to Cash on Hand"
```

Then run:
```bash
php artisan test
```
