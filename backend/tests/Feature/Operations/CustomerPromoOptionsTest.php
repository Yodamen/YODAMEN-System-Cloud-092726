<?php

use App\Models\DiscountRule;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

it('returns all promo codes for the customer filter and marks currently inactive promos', function () {
    $user = foundationUser();

    DiscountRule::query()->create([
        'business_id' => $user->business_id,
        'code' => 'DSR-PROMO-OPTION-001',
        'rule_name' => 'Active Promo',
        'discount_code' => 'ACTIVE10',
        'applies_to' => 'ALL',
        'item_scope' => 'ALL',
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2000-01-01',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
    ]);

    DiscountRule::query()->create([
        'business_id' => $user->business_id,
        'code' => 'DSR-PROMO-OPTION-002',
        'rule_name' => 'Inactive Promo',
        'discount_code' => 'OLD10',
        'applies_to' => 'ALL',
        'item_scope' => 'ALL',
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2000-01-01',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'INACTIVE',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/operations/customers')
        ->assertOk();

    $options = collect($response->json('references.promo_codes'))->keyBy('code');

    expect($options->has('ACTIVE10'))->toBeTrue()
        ->and($options['ACTIVE10']['active'])->toBeTrue()
        ->and($options->has('OLD10'))->toBeTrue()
        ->and($options['OLD10']['active'])->toBeFalse();
});
