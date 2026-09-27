<?php

use App\Models\Customer;
use App\Models\CustomerClassCriterion;
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

it('returns the option catalog for the smart customer audience builder', function () {
    $user = foundationUser();

    $this->actingAs($user)
        ->getJson('/api/v1/operations/customers/audience-options')
        ->assertOk()
        ->assertJsonStructure([
            'options' => [
                'services',
                'service_categories',
                'lead_sources',
                'promo_codes',
                'customer_statuses',
                'customer_types',
                'customer_classes',
                'birth_months',
            ],
        ]);
});


it('uses the configured customer type and class definitions as smart audience fields', function () {
    $user = foundationUser();

    CustomerClassCriterion::query()->create([
        'business_id' => $user->business_id,
        'code' => 'PRIME',
        'name' => 'Prime',
        'min_lifetime_value' => 0,
        'sort_order' => 10,
        'active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-CLASS-001',
        'first_name' => 'Prime',
        'last_name' => 'New',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    $options = $this->actingAs($user)
        ->getJson('/api/v1/operations/customers/audience-options')
        ->assertOk();

    expect(collect($options->json('options.customer_types'))->pluck('value')->all())->toContain('NEW');
    expect(collect($options->json('options.customer_classes'))->pluck('value')->all())->toContain('PRIME');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/operations/customers/audience-preview', [
            'match' => 'ALL',
            'groups' => [[
                'match' => 'ALL',
                'conditions' => [
                    ['field' => 'CUSTOMER_TYPE', 'operator' => 'IN', 'values' => ['NEW']],
                    ['field' => 'CUSTOMER_CLASS', 'operator' => 'IN', 'values' => ['PRIME']],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('matched_count', 1)
        ->assertJsonPath('total_customers', 1);

    expect($response->json('customer_ids'))->toBe([$customer->id]);
});

it('combines grouped all and any conditions like a visual audience rule builder', function () {
    $user = foundationUser();

    $septemberActive = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-001',
        'first_name' => 'September',
        'last_name' => 'Active',
        'birthday' => '1990-09-05',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-002',
        'first_name' => 'October',
        'last_name' => 'Active',
        'birthday' => '1990-10-05',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-003',
        'first_name' => 'September',
        'last_name' => 'Inactive',
        'birthday' => '1990-09-12',
        'registration_date' => '2026-09-18',
        'status' => 'INACTIVE',
    ]);

    $payload = [
        'match' => 'ALL',
        'groups' => [
            [
                'match' => 'ANY',
                'conditions' => [
                    ['field' => 'BIRTH_MONTH', 'operator' => 'IN', 'values' => [9]],
                    ['field' => 'CUSTOMER_STATUS', 'operator' => 'IN', 'values' => ['INACTIVE']],
                ],
            ],
            [
                'match' => 'ALL',
                'conditions' => [
                    ['field' => 'CUSTOMER_STATUS', 'operator' => 'IN', 'values' => ['ACTIVE']],
                ],
            ],
        ],
    ];

    $response = $this->actingAs($user)
        ->postJson('/api/v1/operations/customers/audience-preview', $payload)
        ->assertOk()
        ->assertJsonPath('matched_count', 1)
        ->assertJsonPath('total_customers', 3);

    expect($response->json('customer_ids'))->toBe([$septemberActive->id]);
});

it('can use existing promo eligibility as a smart audience condition', function () {
    $user = foundationUser();

    $qualified = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-PROMO-001',
        'first_name' => 'Promo',
        'last_name' => 'Qualified',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-AUD-PROMO-002',
        'first_name' => 'Promo',
        'last_name' => 'Other',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    DiscountRule::query()->create([
        'business_id' => $user->business_id,
        'code' => 'DSR-AUD-001',
        'rule_name' => 'Audience Specific Promo',
        'discount_code' => 'AUDIENCE10',
        'applies_to' => 'CUSTOMER',
        'customer_id' => $qualified->id,
        'item_scope' => 'ALL',
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2000-01-01',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
    ]);

    $payload = [
        'match' => 'ALL',
        'groups' => [[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'PROMO_QUALIFIED',
                'operator' => 'QUALIFIES',
                'value' => 'AUDIENCE10',
            ]],
        ]],
    ];

    $response = $this->actingAs($user)
        ->postJson('/api/v1/operations/customers/audience-preview', $payload)
        ->assertOk()
        ->assertJsonPath('matched_count', 1);

    expect($response->json('customer_ids'))->toBe([$qualified->id]);
});
