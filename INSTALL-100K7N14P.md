# YODAMEN Update 100K7N14P

## Standardized Workflow Reasons

This update adds a configurable, system-wide standardized reason framework for controlled adjustment, correction, refund, reversal, and rejection workflows.

### Reference Lists

A new system category is added:

- `Workflow Reason`

Each reason contains:

- Code
- Display Value
- Sort Order
- Applicable To workflow context(s)
- Active / Inactive status

Administrators can create custom workflow reasons and choose where each reason appears. System reason codes and their workflow assignments stay fixed to protect downstream logic; display labels may still be edited, and reasons may be activated/deactivated through the existing Reference List controls.

### Standardized request reasons

Reason Category + free-text Details are now used for:

- Inventory Adjustment
- Inventory Adjustment Reversal
- Stock Received Correction
- Stock Received Reversal
- Stock Return to Supplier
- Expense Reversal
- Sales Correction
- Sales Refund
- Sales Reversal
- Petty Cash Reversal
- Business Funding Reversal
- Cash Remittance Reversal
- Cash Deposit Reversal
- Payroll Adjustment Reversal

Existing Sales reason codes such as `PACKAGE_REFUND` and `UNUSED_PACKAGE_BALANCE` are preserved because downstream posting logic depends on them.

### Approval rejection reasons

When an approver rejects a supported request, the system now requires:

1. Rejection Category
2. Rejection Details

The standardized rejection category is centrally maintained under `Workflow Reason` with Applicable To = `Approval Rejection`.

Approval itself does not require an artificial approval reason. Existing optional approval notes remain available where already supported.

### Data integrity

The standardized reason reference is stored separately from the detailed explanation, so management reports can group by standardized reason while preserving the transaction-specific narrative.

Existing historical free-text reasons are preserved. Existing Sales action reason codes are backfilled to the new reference where a matching standardized reason exists.

## Installation

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14P-STANDARDIZED-WORKFLOW-REASONS.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate
```

## Targeted tests

```bash
php artisan test tests/Feature/Foundation/WorkflowReasonTest.php
php artisan test tests/Feature/MasterData/ReferenceDataTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test tests/Feature/Inventory/InventoryApprovalAndRecipeTest.php
php artisan test tests/Feature/Inventory/InventoryOperationsTest.php
php artisan test tests/Feature/Inventory/InventoryClassificationTest.php
php artisan test tests/Feature/Inventory/RetailProductTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Accounting/PettyCashSubledgerTest.php
php artisan test tests/Feature/Accounting/CashHandlingWorkflowTest.php
php artisan test tests/Feature/Accounting/CashDepositAndFundingTest.php
php artisan test tests/Feature/Hr/PayrollTest.php
php artisan test tests/Feature/Hr/LeaveManagementTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
```

Then run the full suite:

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Migration

Migration required:

- `2026_09_27_021000_standardize_workflow_reasons.php`

## Hold notice

100K7O statutory payroll contribution work is not included and remains on hold.
