# YODAMEN 100K7N14I — Supporting Document Framework

## Approved upload policy

### Required before posting/confirmation
- Expenses: at least one supporting document before Post Expense. Draft saving remains allowed without a file.
- Stock Received: at least one receiving document before the receipt is posted.
- Cash Handling > Deposit: at least one deposit proof before deposit posting.
- Supplier Refund Received: at least one refund proof before recording receipt, for Stock Returns and Stock Received corrections.

### Optional
- Stock Return > Supplier
- Inventory Adjustment
- Audit Count
- Petty Cash Replenishment
- Cash Remittance
- Business Funding
- Payroll Adjustment and Payroll Adjustment Reversal
- Cash Advance
- Sales Transaction Correction / Reversal / Refund request
- Stock Received Correction / Reversal request
- Expense Reversal request
- Sales Checkout payment proof
- Retail POS payment proof

Checkout payment proof is optional but the UI recommends it for bank transfer, e-wallet, QR, and other electronic payments.

## File policy
- PDF, JPG/JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, CSV
- Up to 10 MB per file
- Up to 10 files per transaction/request
- Stored on Laravel's private `local` disk
- Attached files are linked to the posted/request record and are not deleted by a reversal
- Attached-document listing/download is authenticated and business/permission scoped

## Install
From project root:

```bash
cd backend
php artisan optimize:clear
php artisan migrate
```

Targeted checks:

```bash
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Inventory/InventorySupplierUomTest.php
php artisan test tests/Feature/Inventory/InventoryOperationsTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Sales/RetailPosTest.php
php artisan test
```

Then:

```bash
cd ../frontend
npm run build
```

## Notes
- This update contains a new migration and `php artisan migrate` is required.
- 100K7O statutory payroll remains held and is not activated by this package.
- This package does not activate the separate Excel/PDF report-export proposal.
