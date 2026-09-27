<?php

use App\Models\ChartOfAccount;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

it('allows a user-created cash account to be selected for payment method accounting mapping', function () {
    $user = foundationUser('ROLE-SA');
    $paymentGroup = ReferenceGroup::query()
        ->where('business_id', $user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();

    // PAYMENT_METHOD is intentionally an administrator-maintained reference group,
    // so MasterDataSeeder creates the group without hard-coded payment methods.
    // Create the test method explicitly just like the Sales feature fixtures do.
    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'BDO-TEST',
        'display_value' => 'BDO Bank Transfer',
        'sort_order' => 10,
        'is_system' => false,
        'active' => true,
    ]);

    $bdo = ChartOfAccount::query()->create([
        'business_id' => $user->business_id,
        'code' => '1002-99',
        'name' => 'Cash in Bank - BDO Test',
        'account_type' => 'ASSET',
        'account_group' => 'Cash and Cash Equivalents',
        'normal_balance' => 'DEBIT',
        'posting_type' => 'POSTING',
        'cash_flow_classification' => 'OPERATING',
        'is_opex' => false,
        'is_cash_account' => true,
        'active' => true,
    ]);

    $product = RetailProduct::query()->create([
        'business_id' => $user->business_id,
        'sku' => 'MAP-ITEM-001',
        'name' => 'Mapping Test Shampoo',
        'category' => 'Shampoo',
        'unit' => 'ml',
        'stock_type' => 'INVENTORY',
        'retail_available' => false,
        'unit_cost' => 10,
        'selling_price' => 0,
        'reorder_point' => 1,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/accounting/mapping')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $bdo->id,
            'code' => '1002-99',
            'name' => 'Cash in Bank - BDO Test',
            'is_cash_account' => true,
        ])
        ->assertJsonFragment([
            'retail_product_id' => $product->id,
            'sku' => 'MAP-ITEM-001',
            'name' => 'Mapping Test Shampoo',
        ]);

    $this->actingAs($user)
        ->putJson('/api/v1/accounting/mapping/payment-methods/'.$method->id, ['cash_account_id' => $bdo->id])
        ->assertOk()
        ->assertJsonPath('mapping.cash_account_id', $bdo->id);

    $this->actingAs($user)
        ->getJson('/api/v1/accounting/mapping')
        ->assertOk()
        ->assertJsonFragment([
            'payment_method_reference_id' => $method->id,
            'cash_account_id' => $bdo->id,
        ]);
});

it('independently controls customer visibility and operating fund source for a mapped bank account', function () {
    $user = foundationUser('ROLE-SA');
    $paymentGroup = ReferenceGroup::query()
        ->where('business_id', $user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();

    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'DISB-BANK',
        'display_value' => 'Disbursement Bank',
        'sort_order' => 20,
        'is_system' => false,
        'active' => true,
    ]);

    $bank = ChartOfAccount::query()->create([
        'business_id' => $user->business_id,
        'code' => '1002-98',
        'name' => 'Cash in Bank - Disbursement Only',
        'account_type' => 'ASSET',
        'account_group' => 'Cash and Cash Equivalents',
        'normal_balance' => 'DEBIT',
        'posting_type' => 'POSTING',
        'cash_flow_classification' => 'OPERATING',
        'is_opex' => false,
        'is_cash_account' => true,
        'is_funding_source' => false,
        'active' => true,
    ]);

    $this->actingAs($user)
        ->putJson('/api/v1/accounting/mapping/payment-methods/'.$method->id, [
            'cash_account_id' => $bank->id,
            'customer_facing' => false,
            'operating_fund_source' => true,
        ])
        ->assertOk()
        ->assertJsonPath('mapping.cash_account_id', $bank->id)
        ->assertJsonPath('mapping.customer_facing', false)
        ->assertJsonPath('operating_fund_source', true);

    expect($bank->fresh()->is_funding_source)->toBeTrue();

    $this->actingAs($user)
        ->getJson('/api/v1/accounting/mapping')
        ->assertOk()
        ->assertJsonFragment([
            'payment_method_reference_id' => $method->id,
            'cash_account_id' => $bank->id,
            'customer_facing' => false,
            'operating_fund_source' => true,
        ]);
});

it('allows petty cash as an operating fund source but never as a customer payment method', function () {
    $user = foundationUser('ROLE-SA');
    $paymentGroup = ReferenceGroup::query()
        ->where('business_id', $user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();

    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'PETTY-CASH',
        'display_value' => 'Petty Cash',
        'sort_order' => 30,
        'is_system' => false,
        'active' => true,
    ]);

    $petty = ChartOfAccount::query()
        ->where('business_id', $user->business_id)
        ->where('system_key', 'PETTY_CASH')
        ->firstOrFail();

    $this->actingAs($user)
        ->putJson('/api/v1/accounting/mapping/payment-methods/'.$method->id, [
            'cash_account_id' => $petty->id,
            'customer_facing' => true,
            'operating_fund_source' => true,
        ])
        ->assertOk()
        ->assertJsonPath('mapping.customer_facing', false)
        ->assertJsonPath('operating_fund_source', true);

    expect($petty->fresh()->is_funding_source)->toBeTrue();

    $this->actingAs($user)
        ->getJson('/api/v1/accounting/mapping')
        ->assertOk()
        ->assertJsonFragment([
            'payment_method_reference_id' => $method->id,
            'cash_account_id' => $petty->id,
            'cash_account_system_key' => 'PETTY_CASH',
            'customer_facing' => false,
            'operating_fund_source' => true,
        ]);
});


it('keeps POS clearing available for collection mapping but never as an operating fund source', function () {
    $user = foundationUser('ROLE-SA');
    $paymentGroup = ReferenceGroup::query()
        ->where('business_id', $user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();

    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'EWALLET-CLEARING',
        'display_value' => 'E-Wallet Clearing Test',
        'sort_order' => 40,
        'is_system' => false,
        'active' => true,
    ]);

    $clearing = ChartOfAccount::query()
        ->where('business_id', $user->business_id)
        ->where('system_key', 'POS_CLEARING')
        ->firstOrFail();

    $clearing->update(['is_funding_source' => true]);

    $this->actingAs($user)
        ->putJson('/api/v1/accounting/mapping/payment-methods/'.$method->id, [
            'cash_account_id' => $clearing->id,
            'customer_facing' => true,
            'operating_fund_source' => true,
        ])
        ->assertOk()
        ->assertJsonPath('mapping.customer_facing', true)
        ->assertJsonPath('operating_fund_source', false);

    expect($clearing->fresh()->is_funding_source)->toBeFalse();
});

it('seeds owner financing and POS clearing accounts as non operating fund sources', function () {
    $user = foundationUser('ROLE-SA');

    $accounts = ChartOfAccount::query()
        ->where('business_id', $user->business_id)
        ->whereIn('system_key', ['POS_CLEARING', 'OWNERS_CAPITAL', 'LOANS_PAYABLE_OWNER'])
        ->get()
        ->keyBy('system_key');

    expect((bool) $accounts['POS_CLEARING']->is_funding_source)->toBeFalse()
        ->and((bool) $accounts['OWNERS_CAPITAL']->is_funding_source)->toBeFalse()
        ->and((bool) $accounts['LOANS_PAYABLE_OWNER']->is_funding_source)->toBeFalse();
});
