# 100K7N14M - Supporting Document Framework Consolidated Rebase

Purpose: safe recovery/install package for the Supporting Document framework after later N14J/N14K/N14L/N14L1 updates.

This package is a last-writer-wins consolidation of:
- 100K7N14I Supporting Document Framework
- 100K7N14J Smart Customer Audience Class/Type UI
- 100K7N14K Excel/PDF Report Export
- 100K7N14L Download Permissions
- 100K7N14L1 Download Permission test fixture fix

Why consolidated:
- N14I modified some files later modified again by J/K/L.
- Installing the original N14I after those later packages could overwrite newer code.
- N14M carries the newest version of every overlapping file while restoring all N14I-only files.

Supporting-document rules retained:
- Expenses: required before posting.
- Stock Received: required.
- Cash Handling > Deposit: required.
- Supplier Refund Received: required.
- Stock Return > Supplier: optional.
- Inventory Adjustment: optional.
- Audit Count: optional.
- Petty Cash Replenishment: optional.
- Cash Remittance: optional.
- Business Funding: optional.
- Payroll Adjustment/Reversal: optional.
- Cash Advance: optional.
- Transaction Correction/Refund/Reversal: optional.
- Stock Received Correction/Reversal: optional.
- Expense Reversal Request: optional.
- Sales Checkout: optional proof, especially bank/e-wallet/QR.
- Retail POS Checkout: optional proof, especially bank/e-wallet/QR.

Later controls preserved:
- Smart Customer Audience Customer Class/Type logic and refreshed modal UI.
- Excel/PDF report export framework.
- Module-level DOWNLOAD permissions in User & Role Management.
- Backend authorization for supporting-document downloads.
- N14L1 required upload_token test fixture fix.

Installation:
1. Extract this ZIP over the current YODAMEN project root.
2. backend: php artisan optimize:clear
3. backend: php artisan migrate
4. backend: run targeted tests below, then php artisan test
5. frontend: npm run build

Targeted tests:
- php artisan test tests/Feature/Foundation/DownloadPermissionsTest.php
- php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
- php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
- php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
- php artisan test tests/Feature/Inventory/InventoryControlTest.php
- php artisan test tests/Feature/Inventory/InventorySupplierUomTest.php
- php artisan test tests/Feature/Inventory/InventoryOperationsTest.php
- php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
- php artisan test tests/Feature/Sales/RetailPosTest.php
- php artisan test tests/Feature/Operations/CustomerAudienceTest.php

No destructive data migration is included. Existing migration records make re-running `php artisan migrate` idempotent.

100K7O remains on hold and is not included.
