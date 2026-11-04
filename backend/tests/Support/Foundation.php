<?php

use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Models\ReferenceValue;
use App\Models\ChartOfAccount;
use App\Models\AccountingPaymentMethodMapping;

require_once __DIR__.'/SubscriptionLicenses.php';

function foundationUser(string $roleCode = 'ROLE-SA', array $attributes = [], string $tier = 'premium'): User
{
    $business = Business::query()->where('code', 'YDM')->firstOrFail();
    // Existing business tests exercise the full app under a valid Premium license.
    if (! $business->license_token) {
        $business->forceFill(['license_token' => subscriptionTestCode($business, $tier), 'license_revision' => 1])->save();
    }
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
