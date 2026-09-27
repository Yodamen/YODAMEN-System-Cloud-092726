# YODAMEN Update 100K7H — Stock Receipt Payment UX

## Purpose
Refine Receive Stock settlement behavior and layout.

## Changes
- When Payment Status is **Unpaid**, payment allocation fields are hidden.
- Unpaid receipts post the **entire Grand Total** to Accounts Payable.
- Unpaid receipts reject payment splits at the backend to prevent hidden/stale/API payment allocations.
- Supplier settlement for an unpaid receipt is handled later through the Accounts Payable module.
- When Payment Status is **Paid**, the payment allocation panel appears.
- Paid receipts must be fully allocated before posting.
- Payment allocation UI is redesigned into a compact column layout:
  - Mode of Payment
  - Amount
  - Reference
  - Remove
- Split Payment remains available for Paid receipts.
- The Unpaid state shows a compact Accounts Payable summary instead of empty payment controls.
- Inventory regression coverage is aligned with the new rule.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7H-STOCK-RECEIPT-PAYMENT-UX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan test tests/Feature/Inventory/InventoryControlTest.php

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No migration is required.
