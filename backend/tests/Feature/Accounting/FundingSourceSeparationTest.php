<?php

use App\Models\AccountingPaymentMethodMapping;
use App\Models\ChartOfAccount;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->user = foundationUser('ROLE-SA');
});

test('customer checkout methods and operating funding sources keep owner capital and cash on hand protected', function () {
    $group = ReferenceGroup::query()
        ->where('business_id', $this->user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();

    $bank = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('system_key', 'CASH_IN_BANK')
        ->firstOrFail();

    $bdo = ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'BDO-CUSTOMER-TEST',
        'display_value' => 'BDO',
        'sort_order' => 10,
        'is_system' => false,
        'active' => true,
    ]);
    $ownerMethod = ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'OWNER-CAPITAL-TEST',
        'display_value' => "Owner's Capital",
        'sort_order' => 20,
        'is_system' => false,
        'active' => true,
    ]);

    AccountingPaymentMethodMapping::query()->create([
        'business_id' => $this->user->business_id,
        'payment_method_reference_id' => $bdo->id,
        'cash_account_id' => $bank->id,
        'customer_facing' => true,
    ]);
    AccountingPaymentMethodMapping::query()->create([
        'business_id' => $this->user->business_id,
        'payment_method_reference_id' => $ownerMethod->id,
        'cash_account_id' => $bank->id,
        'customer_facing' => false,
    ]);

    $bootstrap = $this->actingAs($this->user)->getJson('/api/v1/operations/bookings/bootstrap')->assertOk();
    $names = collect($bootstrap->json('payment_methods'))->pluck('name')->all();
    expect($names)->toContain('BDO')->not->toContain("Owner's Capital");

    $ownerCapital = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('system_key', 'OWNERS_CAPITAL')
        ->firstOrFail();
    $cashOnHand = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('system_key', 'CASH_ON_HAND')
        ->firstOrFail();

    $expenseOptions = $this->actingAs($this->user)->getJson('/api/v1/expenses')->assertOk();
    $fundingIds = collect($expenseOptions->json('options.funding_sources'))->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($fundingIds)
        ->toContain((int) $bank->id)
        ->not->toContain((int) $ownerCapital->id)
        ->not->toContain((int) $cashOnHand->id);
});

test('paid expense cannot use owner capital directly as an operating funding source', function () {
    $rent = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('code', '6101')
        ->firstOrFail();
    $ownerCapital = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('system_key', 'OWNERS_CAPITAL')
        ->firstOrFail();

    $this->actingAs($this->user)->postJson('/api/v1/expenses', [
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => '2026-09-25',
        'vendor_payee' => 'Owner-paid supplier',
        'description' => 'Business rent paid personally by owner',
        'payment_status' => 'PAID',
        'payment_splits' => [[
            'funding_account_id' => $ownerCapital->id,
            'amount' => 1000,
            'reference' => 'OWNER-FUND-001',
        ]],
        'lines' => [[
            'account_id' => $rent->id,
            'description' => 'Rent',
            'amount' => 1000,
        ]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Select a valid active Expense / Supplier Funding Source.');
});
