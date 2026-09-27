# YODAMEN Update 79B - Regression Fix

This patch fixes the two Update 79 regression test failures:

1. Leave entitlement test alignment
   - Personnel with `paid_leave_entitled = false` are blocked from filing LEAVE requests.
   - DAY_OFF remains available.
   - The regression test now reflects the approved business rule.

2. Non-inventory normalization
   - `NON_INVENTORY` products always persist and return `reorder_point = 0`.
   - `NON_INVENTORY` products always persist and return `commission_eligible = false`.
   - The Product Masterlist UI resets/disables Reorder Point for non-inventory items.

No database migration is included in this patch.

## Install

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-79B-REGRESSION-FIX.zip -d .
cd backend
php artisan optimize:clear
php artisan test
```

Then verify the frontend:

```bash
cd ../frontend
npm run build
npm run dev
```
