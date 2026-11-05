<?php

return [
    // Public verification key only. The signing key must never be deployed.
    'public_key' => 'j5Al8HUNfTmKWvqTgCa8roTsrZ9fR9wzXihDNVX23Rk=',

    'trial_limit' => 50,

    'plans' => [
        'basic' => ['name' => 'Basic', 'features' => ['customers', 'operations', 'sales']],
        'standard' => ['name' => 'Standard', 'features' => ['customers', 'operations', 'sales', 'expenses', 'inventory', 'hr']],
        'premium' => ['name' => 'Premium', 'features' => ['customers', 'operations', 'sales', 'expenses', 'inventory', 'hr', 'accounting', 'reports']],
    ],

    'features' => [
        'customers' => ['name' => 'Customer List', 'modules' => ['customers', 'customer-type-criteria', 'customer-class-criteria', 'service-balances']],
        'operations' => ['name' => 'Operations', 'modules' => ['bookings', 'queue', 'servicing', 'visits', 'fulfillment']],
        'sales' => ['name' => 'Sales', 'modules' => ['sales', 'retail-pos', 'sales-dashboard', 'payments', 'packages', 'package-setup', 'collections', 'cash-deposits', 'cash-remittances', 'discount-rules']],
        'expenses' => ['name' => 'Expenses', 'modules' => ['expenses', 'petty-cash']],
        'inventory' => ['name' => 'Inventory', 'modules' => ['inventory-dashboard', 'inventory-master', 'supplier-master', 'stock-received', 'stock-returns', 'inventory-movements', 'service-consumption', 'service-costing', 'inventory-count', 'inventory-adjustments', 'reorder']],
        'hr' => ['name' => 'HR & Payroll', 'modules' => ['my-payroll', 'my-profit', 'my-attendance', 'my-leave', 'employees', 'attendance', 'daily-compensation', 'commissions', 'cash-advances', 'payroll', 'payslips', 'leave', 'commission-rules', 'incentive-rules']],
        'accounting' => ['name' => 'Accounting', 'modules' => ['coa', 'cash-accounts', 'accounting-mapping', 'general-ledger', 'trial-balance', 'cash-management', 'cash-flow', 'daily-closing', 'financial-statements', 'ar', 'ap', 'bank-reconciliation', 'profit-sharing', 'opening-balances']],
        'reports' => ['name' => 'Reports', 'modules' => ['report-sales', 'report-customers', 'report-services', 'report-staff', 'report-hr', 'report-accounting', 'report-inventory']],
    ],

    // These are necessary to administer a business on every plan.
    'core_modules' => ['dashboard', 'business-setup', 'branding', 'references', 'services-pricing', 'users', 'audit'],
];
