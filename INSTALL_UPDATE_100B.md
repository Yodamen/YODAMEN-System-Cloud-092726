# YODAMEN Update 100B — Retail Inventory Waterfall Hardening

## Purpose
This update closes the remaining gap where a financially successful Booking checkout could leave a retail product without a stock deduction.

## Locked behavior
1. Financial finalization is the inventory issue point for Booking/Visit retail purchases.
2. Every active `PRODUCT` sale item must have either:
   - an existing `RETAIL_SALE` inventory movement, or
   - a resolvable Product Master link that can be posted immediately.
3. Checkout can no longer silently skip a product line with no Product Master link.
4. If the Sale Item lost its direct product link, the system tries to repair it from the Visit retail line or SKU snapshot before failing.
5. Inventory deduction is idempotent. An existing `RETAIL_SALE` movement is never posted twice.
6. Approved post-checkout Corrections no longer silently skip retail inventory when older data is missing the original `RETAIL_SALE` movement. The correction posts a recovery movement to bring inventory to the correct final quantity.
7. Checkout + payment + inventory remain atomic. If stock posting fails, financial finalization is rolled back.

## Files
- `backend/app/Services/Sales/CheckoutSettlementService.php`
- `backend/app/Services/Sales/TransactionActionPostingService.php`
- `backend/tests/Feature/Sales/RetailBookingInventoryRegressionTest.php`

## Install
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100B-RETAIL-INVENTORY-HARDENING.zip -d .

cd backend
php artisan optimize:clear
php artisan test tests/Feature/Sales/RetailBookingInventoryRegressionTest.php
php artisan test
```

No migration is included or required.

## Expected regression behavior
The targeted test contains two cases:
- Product Master Booking retail purchase: stock starts at 10, a paid checkout of Qty 2 ends at 8 and creates one `RETAIL_SALE` movement of `-2`.
- Legacy/unlinked PRODUCT line: checkout is rejected instead of becoming Paid while skipping inventory.

## Manual smoke test
1. Create or use an active Product Master item with stock.
2. Add it to a Booking under Retail Purchases.
3. Complete the Booking/Visit.
4. Finalize Checkout as Paid.
5. Confirm On Hand decreased by the purchased quantity in Basic UOM.
6. Confirm an Inventory Movement exists with movement type `RETAIL_SALE` and the Sale Item reference.
7. Repeat/open the same finalized transaction only for viewing. No second movement should be created.

## Important
Do not use `migrate:fresh`, `db:wipe`, rollback, or DEVRESET for this update.
