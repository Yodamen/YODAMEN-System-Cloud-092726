<?php

use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\Role;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds every system account required by automatic operational accounting', function () {
    $this->seed(FoundationSeeder::class);

    $business = Business::query()->firstOrFail();
    $requiredKeys = [
        'CASH_ON_HAND',
        'CASH_IN_BANK',
        'POS_CLEARING',
        'PETTY_CASH',
        'AR_CUSTOMERS',
        'STAFF_CASH_ADVANCES',
        'INVENTORY_TREATMENT_CHEMICALS',
        'INVENTORY_BARBER_CONSUMABLES',
        'INVENTORY_RETAIL_PRODUCTS',
        'ACCOUNTS_PAYABLE',
        'ACCRUED_SALARIES',
        'ACCRUED_COMMISSIONS',
        'CUSTOMER_ADVANCES',
        'DEFERRED_PACKAGE_REVENUE',
        'SERVICE_REVENUE',
        'SALES_DISCOUNTS',
        'SALES_RETURNS',
        'PACKAGE_REVENUE',
        'RETAIL_PRODUCT_SALES',
        'INVENTORY_COUNT_GAIN',
        'COS_SERVICE_CHEMICALS',
        'COS_SERVICE_CONSUMABLES',
        'INVENTORY_COUNT_VARIANCE',
        'COS_BARBER_COMMISSIONS',
        'COS_THERAPIST_COMMISSIONS',
        'COS_OTHER_SERVICE_COMMISSIONS',
        'COS_RETAIL_PRODUCTS',
        'SALARY_BARBERS',
        'SALARY_THERAPISTS',
        'SALARY_ADMIN',
        'RETAIL_SALES_COMMISSIONS',
        'OPENING_BALANCE_EQUITY',
    ];

    $accounts = ChartOfAccount::query()
        ->where('business_id', $business->id)
        ->whereIn('system_key', $requiredKeys)
        ->get()
        ->keyBy('system_key');

    expect($accounts->keys()->sort()->values()->all())
        ->toBe(collect($requiredKeys)->sort()->values()->all());

    foreach ($requiredKeys as $key) {
        expect($accounts[$key]->active)->toBeTrue()
            ->and($accounts[$key]->posting_type)->toBe('POSTING');
    }
});

it('seeds active accounting permissions consistently for manager and self service profit access', function () {
    $this->seed(FoundationSeeder::class);

    $manager = Role::query()->where('code', 'ROLE-MGR')->with('permissions')->firstOrFail();
    $managerCodes = $manager->permissions->pluck('code');

    foreach ([
        'petty-cash.CREATE',
        'petty-cash.REVERSE',
        'ap.VIEW',
        'ap.PAY',
        'general-ledger.VIEW',
        'trial-balance.VIEW',
        'daily-closing.CLOSE',
        'daily-closing.REOPEN',
        'bank-reconciliation.CREATE',
        'bank-reconciliation.EDIT',
        'bank-reconciliation.RECONCILE',
        'accounting-mapping.EDIT',
    ] as $permissionCode) {
        expect($managerCodes)->toContain($permissionCode);
    }

    Role::query()->with('permissions')->get()->each(function (Role $role) {
        expect($role->permissions->pluck('code'))->toContain('my-profit.VIEW');
    });
});
