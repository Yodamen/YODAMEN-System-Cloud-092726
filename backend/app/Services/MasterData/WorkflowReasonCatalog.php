<?php

namespace App\Services\MasterData;

final class WorkflowReasonCatalog
{
    public const GROUP_CODE = 'WORKFLOW_REASON';
    public const INVENTORY_ADJUSTMENT = 'INVENTORY_ADJUSTMENT';
    public const INVENTORY_ADJUSTMENT_REVERSAL = 'INVENTORY_ADJUSTMENT_REVERSAL';
    public const STOCK_RECEIPT_CORRECTION = 'STOCK_RECEIPT_CORRECTION';
    public const STOCK_RECEIPT_REVERSAL = 'STOCK_RECEIPT_REVERSAL';
    public const STOCK_RETURN = 'STOCK_RETURN';
    public const EXPENSE_REVERSAL = 'EXPENSE_REVERSAL';
    public const SALES_CORRECTION = 'SALES_CORRECTION';
    public const SALES_REFUND = 'SALES_REFUND';
    public const SALES_REVERSAL = 'SALES_REVERSAL';
    public const PETTY_CASH_REVERSAL = 'PETTY_CASH_REVERSAL';
    public const BUSINESS_FUNDING_REVERSAL = 'BUSINESS_FUNDING_REVERSAL';
    public const CASH_REMITTANCE_REVERSAL = 'CASH_REMITTANCE_REVERSAL';
    public const CASH_DEPOSIT_REVERSAL = 'CASH_DEPOSIT_REVERSAL';
    public const PAYROLL_ADJUSTMENT_REVERSAL = 'PAYROLL_ADJUSTMENT_REVERSAL';
    public const APPROVAL_REJECTION = 'APPROVAL_REJECTION';

    public static function contexts(): array
    {
        return [
            'INVENTORY_ADJUSTMENT' => 'Inventory Adjustment',
            'INVENTORY_ADJUSTMENT_REVERSAL' => 'Inventory Adjustment Reversal',
            'STOCK_RECEIPT_CORRECTION' => 'Stock Received Correction',
            'STOCK_RECEIPT_REVERSAL' => 'Stock Received Reversal',
            'STOCK_RETURN' => 'Stock Return to Supplier',
            'EXPENSE_REVERSAL' => 'Expense Reversal',
            'SALES_CORRECTION' => 'Sales Correction',
            'SALES_REFUND' => 'Sales Refund',
            'SALES_REVERSAL' => 'Sales Reversal',
            'PETTY_CASH_REVERSAL' => 'Petty Cash Reversal',
            'BUSINESS_FUNDING_REVERSAL' => 'Business Funding Reversal',
            'CASH_REMITTANCE_REVERSAL' => 'Cash Remittance Reversal',
            'CASH_DEPOSIT_REVERSAL' => 'Cash Deposit Reversal',
            'PAYROLL_ADJUSTMENT_REVERSAL' => 'Payroll Adjustment Reversal',
            'APPROVAL_REJECTION' => 'Approval Rejection',
        ];
    }

    public static function contextLabel(string $context): ?string
    {
        return self::contexts()[strtoupper(trim($context))] ?? null;
    }

    public static function validContext(string $context): bool
    {
        return self::contextLabel($context) !== null;
    }

    /**
     * Default standardized reasons. A single reason may be available in several workflows.
     * Administrators may deactivate these or add custom values under Reference Lists.
     */
    public static function defaults(): array
    {
        $allRequestContexts = array_values(array_filter(array_keys(self::contexts()), fn (string $context) => $context !== 'APPROVAL_REJECTION'));

        return [
            ['DAMAGE_BREAKAGE', 'Damage / Breakage', ['INVENTORY_ADJUSTMENT', 'STOCK_RETURN']],
            ['SPOILAGE_EXPIRY', 'Spoilage / Expiry', ['INVENTORY_ADJUSTMENT', 'STOCK_RETURN']],
            ['LOSS_MISSING', 'Loss / Missing Stock', ['INVENTORY_ADJUSTMENT']],
            ['THEFT_SUSPECTED', 'Theft / Suspected Theft', ['INVENTORY_ADJUSTMENT']],
            ['FOUND_STOCK', 'Found Stock', ['INVENTORY_ADJUSTMENT']],
            ['OPENING_CUTOVER_CORRECTION', 'Opening / Cutover Correction', ['INVENTORY_ADJUSTMENT']],
            ['UOM_CORRECTION', 'UOM / Conversion Correction', ['INVENTORY_ADJUSTMENT', 'STOCK_RECEIPT_CORRECTION']],

            ['WRONG_QUANTITY', 'Wrong Quantity', ['STOCK_RECEIPT_CORRECTION', 'SALES_CORRECTION', 'INVENTORY_ADJUSTMENT_REVERSAL']],
            ['WRONG_UNIT_COST', 'Wrong Unit Cost', ['STOCK_RECEIPT_CORRECTION']],
            ['WRONG_ITEM', 'Wrong Item', ['STOCK_RECEIPT_CORRECTION', 'STOCK_RECEIPT_REVERSAL', 'INVENTORY_ADJUSTMENT_REVERSAL']],
            ['SUPPLIER_DOCUMENT_CORRECTION', 'Supplier Document Correction', ['STOCK_RECEIPT_CORRECTION']],
            ['DUPLICATE_RECEIPT', 'Duplicate Stock Receipt', ['STOCK_RECEIPT_REVERSAL']],
            ['WRONG_SUPPLIER', 'Wrong Supplier', ['STOCK_RECEIPT_REVERSAL']],
            ['WRONG_BRANCH', 'Wrong Branch', ['STOCK_RECEIPT_REVERSAL', 'CASH_REMITTANCE_REVERSAL']],
            ['ACCIDENTAL_RECEIPT', 'Accidental Stock Receipt', ['STOCK_RECEIPT_REVERSAL']],

            ['QUALITY_ISSUE', 'Quality Issue', ['STOCK_RETURN']],
            ['WRONG_ITEM_DELIVERED', 'Wrong Item Delivered', ['STOCK_RETURN']],
            ['EXCESS_DELIVERY', 'Excess Delivery', ['STOCK_RETURN']],
            ['SUPPLIER_RECALL', 'Supplier Recall', ['STOCK_RETURN']],

            ['DUPLICATE_EXPENSE', 'Duplicate Expense', ['EXPENSE_REVERSAL']],
            ['WRONG_AMOUNT', 'Wrong Amount', ['EXPENSE_REVERSAL', 'PETTY_CASH_REVERSAL', 'BUSINESS_FUNDING_REVERSAL', 'CASH_REMITTANCE_REVERSAL', 'CASH_DEPOSIT_REVERSAL', 'PAYROLL_ADJUSTMENT_REVERSAL']],
            ['WRONG_ACCOUNT', 'Wrong Account', ['EXPENSE_REVERSAL', 'BUSINESS_FUNDING_REVERSAL', 'CASH_DEPOSIT_REVERSAL']],
            ['WRONG_VENDOR', 'Wrong Vendor / Payee', ['EXPENSE_REVERSAL']],
            ['WRONG_PAYMENT_SOURCE', 'Wrong Payment / Funding Source', ['EXPENSE_REVERSAL', 'PETTY_CASH_REVERSAL']],
            ['VOIDED_EXPENSE', 'Voided / Cancelled Expense', ['EXPENSE_REVERSAL']],

            // Existing Sales reason codes are preserved because downstream correction/refund logic uses them.
            ['WRONG_SERVICE', 'Wrong Service', ['SALES_CORRECTION']],
            ['WRONG_PRODUCT', 'Wrong Product', ['SALES_CORRECTION']],
            ['WRONG_STAFF', 'Wrong Staff', ['SALES_CORRECTION']],
            ['DUPLICATE_LINE', 'Duplicate Line', ['SALES_CORRECTION']],
            ['WRONG_PRICE', 'Wrong Price', ['SALES_CORRECTION']],
            ['WRONG_PACKAGE', 'Wrong Package', ['SALES_CORRECTION']],
            ['WRONG_SERVICE_CREDIT', 'Wrong Service Credit', ['SALES_CORRECTION']],
            ['CORRECTION_REFUND_DUE', 'Correction Refund Due', ['SALES_REFUND']],
            ['SERVICE_ISSUE', 'Service Issue / Customer Refund', ['SALES_REFUND']],
            ['PRODUCT_RETURN', 'Product Return', ['SALES_REFUND']],
            ['OVERPAYMENT', 'Overpayment', ['SALES_REFUND']],
            ['PACKAGE_REFUND', 'Package Refund', ['SALES_REFUND']],
            ['UNUSED_PACKAGE_BALANCE', 'Unused Package Balance', ['SALES_REFUND']],
            ['DUPLICATE_TRANSACTION', 'Duplicate Transaction', ['SALES_REVERSAL']],
            ['WRONG_CUSTOMER', 'Wrong Customer', ['SALES_REVERSAL']],
            ['WRONG_BOOKING', 'Wrong Booking', ['SALES_REVERSAL']],
            ['ACCIDENTAL_TRANSACTION', 'Accidental Transaction', ['SALES_REVERSAL']],
            ['TEST_TRANSACTION', 'Test Transaction', ['SALES_REVERSAL']],
            ['SYSTEM_ERROR', 'System Error', ['SALES_REVERSAL', 'STOCK_RECEIPT_REVERSAL']],
            ['PROCESSING_ERROR', 'Processing Error', ['SALES_REVERSAL']],
            ['UNAUTHORIZED_TRANSACTION', 'Unauthorized Transaction', ['SALES_REVERSAL']],
            ['INVALID_TRANSACTION', 'Invalid Transaction', ['SALES_REVERSAL']],

            ['DUPLICATE_REPLENISHMENT', 'Duplicate Replenishment', ['PETTY_CASH_REVERSAL']],
            ['DUPLICATE_FUNDING', 'Duplicate Business Funding', ['BUSINESS_FUNDING_REVERSAL']],
            ['WRONG_FUNDING_TYPE', 'Wrong Funding Type', ['BUSINESS_FUNDING_REVERSAL']],
            ['DUPLICATE_REMITTANCE', 'Duplicate Cash Remittance', ['CASH_REMITTANCE_REVERSAL']],
            ['WRONG_CUSTODIAN', 'Wrong Custodian', ['CASH_REMITTANCE_REVERSAL']],
            ['DUPLICATE_DEPOSIT', 'Duplicate Cash Deposit', ['CASH_DEPOSIT_REVERSAL']],
            ['WRONG_REMITTANCE_LINK', 'Wrong Remittance Link', ['CASH_DEPOSIT_REVERSAL']],
            ['DUPLICATE_ADJUSTMENT', 'Duplicate Adjustment', ['PAYROLL_ADJUSTMENT_REVERSAL', 'INVENTORY_ADJUSTMENT_REVERSAL']],
            ['WRONG_EMPLOYEE', 'Wrong Employee', ['PAYROLL_ADJUSTMENT_REVERSAL']],
            ['WRONG_ADJUSTMENT_TYPE', 'Wrong Adjustment Type', ['PAYROLL_ADJUSTMENT_REVERSAL']],
            ['WRONG_EFFECTIVE_DATE', 'Wrong Effective Date', ['PAYROLL_ADJUSTMENT_REVERSAL']],
            ['ACCIDENTAL_ENTRY', 'Accidental / Erroneous Entry', ['PETTY_CASH_REVERSAL', 'BUSINESS_FUNDING_REVERSAL', 'CASH_REMITTANCE_REVERSAL', 'CASH_DEPOSIT_REVERSAL', 'PAYROLL_ADJUSTMENT_REVERSAL', 'INVENTORY_ADJUSTMENT_REVERSAL']],

            ['INSUFFICIENT_SUPPORT', 'Insufficient Supporting Documents', ['APPROVAL_REJECTION']],
            ['INVALID_REQUEST', 'Invalid Request', ['APPROVAL_REJECTION']],
            ['INCORRECT_DETAILS', 'Incorrect / Incomplete Details', ['APPROVAL_REJECTION']],
            ['DUPLICATE_REQUEST', 'Duplicate Request', ['APPROVAL_REJECTION']],
            ['POLICY_NOT_MET', 'Policy / Control Requirement Not Met', ['APPROVAL_REJECTION']],
            ['OUTSIDE_ALLOWED_PERIOD', 'Outside Allowed Period', ['APPROVAL_REJECTION']],
            ['SOURCE_CHANGED', 'Source Transaction Changed', ['APPROVAL_REJECTION']],

            ['OTHER', 'Other', array_values(array_unique(array_merge($allRequestContexts, ['APPROVAL_REJECTION'])))],
        ];
    }
}
