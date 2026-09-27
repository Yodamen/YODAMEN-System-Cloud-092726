<?php

use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Models\ReferenceValue;
use App\Models\ChartOfAccount;
use App\Models\AccountingPaymentMethodMapping;

function foundationUser(string $roleCode = 'ROLE-SA', array $attributes = []): User
{
    $business = Business::query()->where('code', 'YDM')->firstOrFail();
    $branch = Branch::query()->where('code', 'YDM-SJC')->firstOrFail();
    $role = Role::query()->where('code', $roleCode)->firstOrFail();

    return User::factory()->create(array_merge([
        'business_id' => $business->id,
        'default_branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => '123456',
    ], $attributes));
}


function mapCustomerPaymentMethod(User $user, ReferenceValue $method, string $accountKey = 'CASH_ON_HAND'): AccountingPaymentMethodMapping
{
    $account = ChartOfAccount::query()
        ->where('business_id', $user->business_id)
        ->where('system_key', $accountKey)
        ->where('active', true)
        ->where('posting_type', 'POSTING')
        ->firstOrFail();

    return AccountingPaymentMethodMapping::query()->updateOrCreate(
        [
            'business_id' => $user->business_id,
            'payment_method_reference_id' => $method->id,
        ],
        [
            'cash_account_id' => $account->id,
            'customer_facing' => true,
        ],
    );
}
