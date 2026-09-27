# YODAMEN 100K7M1 Regression Alignment Fix

Purpose:
- Preserve the 100K7M rule that Cash on Hand is not an operational expense/supplier funding source.
- Align legacy Expense tests to use Cash in Bank for paid expenses.
- Mark the InventoryControlTest BDO fixture as an enabled funding source.
- Reject legacy Stock Receipt payment-method mappings that point to a cash account which is not enabled as an Expense / Supplier Funding Source.

Install from project root, then clear Laravel caches. No migration is included.

Recommended validation:
1. php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
2. php artisan test tests/Feature/Inventory/InventoryControlTest.php
3. php artisan test

Expected full suite if no other local changes exist: 247 passed.
