<?php

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\ReferenceGroup;
use App\Models\Service;
use App\Services\Discounts\DiscountRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);

    $this->user = foundationUser('ROLE-SA');
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $this->service = Service::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'SRV-DSC-001',
        'name' => 'Discount Test Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 30,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    $this->customer = Customer::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'CUS-DSC-001',
        'first_name' => 'Discount',
        'last_name' => 'Customer',
        'mobile_number' => '09171110000',
        'mobile_normalized' => '09171110000',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function discountRulePayload(array $overrides = []): array
{
    return array_merge([
        'rule_name' => 'General Save 10',
        'discount_code' => 'SAVE10',
        'branch_id' => null,
        'applies_to' => 'ALL',
        'customer_id' => null,
        'customer_type' => null,
        'min_lifetime_value' => null,
        'max_lifetime_value' => null,
        'last_visit_operator' => null,
        'last_visit_days_min' => null,
        'last_visit_days_max' => null,
        'item_scope' => 'ALL',
        'service_id' => null,
        'service_category_reference_id' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2026-09-18',
        'effective_until' => null,
        'priority' => 0,
        'stackable' => true,
        'status' => 'ACTIVE',
        'notes' => null,
    ], $overrides);
}

test('discount rules index returns flexible form options', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/admin/discount-rules')
        ->assertOk()
        ->assertJsonPath('phase.checkout_active', true)
        ->assertJsonPath('phase.stacking_supported', true)
        ->assertJsonPath('options.services.0.id', $this->service->id);
});

test('discount code can contain overlapping flexible rules', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'rule_name' => 'All Customer Haircut 10',
            'service_id' => $this->service->id,
        ]))->assertCreated();

    $specific = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'rule_name' => 'Specific Customer Haircut 20',
            'applies_to' => 'CUSTOMER',
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'rate_value' => 20,
        ]))->assertCreated()->json('rule.id');

    expect(DiscountRule::query()->where('discount_code', 'SAVE10')->count())->toBe(2)
        ->and(DiscountRule::query()->findOrFail($specific)->customer_id)->toBe($this->customer->id);
});

test('higher priority resolves equal specificity', function () {
    $firstId = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'rule_name' => 'Low Priority',
            'service_id' => $this->service->id,
            'priority' => 1,
        ]))->assertCreated()->json('rule.id');

    $highId = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'rule_name' => 'High Priority',
            'service_id' => $this->service->id,
            'priority' => 9,
        ]))->assertCreated()->json('rule.id');

    $rules = app(DiscountRuleService::class)->activeCandidatesForCode(
        $this->user->business_id,
        'SAVE10',
        CarbonImmutable::parse('2026-09-18'),
        $this->user->default_branch_id,
    );

    $sale = new \App\Models\Sale([
        'business_id' => $this->user->business_id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->user->default_branch_id,
    ]);
    $sale->setRelation('booking', new \App\Models\Booking(['customer_type' => 'NEW']));
    $item = new \App\Models\SaleItem(['service_id' => $this->service->id, 'item_type' => 'SERVICE']);
    $item->setRelation('service', $this->service);

    $resolved = app(DiscountRuleService::class)->resolveFromCandidates($sale, $item, $rules);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($highId)
        ->and($resolved->id)->not->toBe($firstId);
});

test('percentage discount above one hundred is rejected', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload(['rate_value' => 101]))
        ->assertStatus(422);
});

test('discount rule edit preserves the same row', function () {
    $id = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload())
        ->assertCreated()->json('rule.id');

    $this->actingAs($this->user)
        ->putJson('/api/v1/admin/discount-rules/'.$id, discountRulePayload([
            'rule_name' => 'Edited Save 12',
            'rate_value' => 12,
            'priority' => 5,
        ]))
        ->assertOk()
        ->assertJsonPath('rule.rule_name', 'Edited Save 12')
        ->assertJsonPath('rule.rate_value', 12)
        ->assertJsonPath('rule.priority', 5);

    expect(DiscountRule::query()->count())->toBe(1);
});


test('discount rule stores lifetime value and last visit criteria', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'rule_name' => 'Loyal Comeback',
            'discount_code' => 'LOYALCOME',
            'min_lifetime_value' => 5000,
            'max_lifetime_value' => 25000,
            'last_visit_operator' => 'MORE_THAN',
            'last_visit_days_min' => 30,
        ]))
        ->assertCreated()
        ->assertJsonPath('rule.min_lifetime_value', 5000)
        ->assertJsonPath('rule.max_lifetime_value', 25000)
        ->assertJsonPath('rule.last_visit_operator', 'MORE_THAN')
        ->assertJsonPath('rule.last_visit_days_min', 30)
        ->assertJsonPath('rule.last_visit_days_max', null);

    $rule = DiscountRule::query()->findOrFail($response->json('rule.id'));

    expect((float) $rule->min_lifetime_value)->toBe(5000.0)
        ->and((float) $rule->max_lifetime_value)->toBe(25000.0)
        ->and($rule->last_visit_operator)->toBe('MORE_THAN')
        ->and($rule->last_visit_days_min)->toBe(30)
        ->and($rule->last_visit_days_max)->toBeNull();
});

test('lifetime value criteria are enforced when resolving a discount rule', function () {
    $ruleId = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'discount_code' => 'VIP20',
            'min_lifetime_value' => 10000,
            'service_id' => $this->service->id,
        ]))
        ->assertCreated()
        ->json('rule.id');

    $rules = app(DiscountRuleService::class)->activeCandidatesForCode(
        $this->user->business_id,
        'VIP20',
        CarbonImmutable::parse('2026-09-18'),
        $this->user->default_branch_id,
    );

    $sale = new \App\Models\Sale([
        'business_id' => $this->user->business_id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->user->default_branch_id,
    ]);
    $sale->setRelation('booking', new \App\Models\Booking(['customer_type' => 'RETURNEE']));

    $item = new \App\Models\SaleItem([
        'service_id' => $this->service->id,
        'item_type' => 'SERVICE',
    ]);
    $item->setRelation('service', $this->service);

    $service = app(DiscountRuleService::class);

    expect($service->resolveFromCandidates($sale, $item, $rules, [
        'lifetime_value' => 9999.99,
        'days_since_last_visit' => 45,
    ]))->toBeNull();

    $resolved = $service->resolveFromCandidates($sale, $item, $rules, [
        'lifetime_value' => 10000,
        'days_since_last_visit' => 45,
    ]);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($ruleId);
});

test('last visit criteria support more than within between and never visited', function () {
    $service = app(DiscountRuleService::class);

    $sale = new \App\Models\Sale([
        'business_id' => $this->user->business_id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->user->default_branch_id,
    ]);
    $sale->setRelation('booking', new \App\Models\Booking(['customer_type' => 'RETURNEE']));

    $item = new \App\Models\SaleItem([
        'service_id' => $this->service->id,
        'item_type' => 'SERVICE',
    ]);
    $item->setRelation('service', $this->service);

    $cases = [
        ['code' => 'COMEBACK30', 'operator' => 'MORE_THAN', 'min' => 30, 'max' => null, 'match_days' => 31, 'miss_days' => 30],
        ['code' => 'RECENT14', 'operator' => 'WITHIN', 'min' => null, 'max' => 14, 'match_days' => 14, 'miss_days' => 15],
        ['code' => 'WINDOW', 'operator' => 'BETWEEN', 'min' => 21, 'max' => 45, 'match_days' => 30, 'miss_days' => 46],
    ];

    foreach ($cases as $case) {
        $this->actingAs($this->user)
            ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
                'discount_code' => $case['code'],
                'last_visit_operator' => $case['operator'],
                'last_visit_days_min' => $case['min'],
                'last_visit_days_max' => $case['max'],
                'service_id' => $this->service->id,
            ]))
            ->assertCreated();

        $rules = $service->activeCandidatesForCode(
            $this->user->business_id,
            $case['code'],
            CarbonImmutable::parse('2026-09-18'),
            $this->user->default_branch_id,
        );

        expect($service->resolveFromCandidates($sale, $item, $rules, [
            'lifetime_value' => 0,
            'days_since_last_visit' => $case['match_days'],
        ]))->not->toBeNull();

        expect($service->resolveFromCandidates($sale, $item, $rules, [
            'lifetime_value' => 0,
            'days_since_last_visit' => $case['miss_days'],
        ]))->toBeNull();
    }

    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'discount_code' => 'FIRSTVISIT',
            'last_visit_operator' => 'NEVER',
            'service_id' => $this->service->id,
        ]))
        ->assertCreated();

    $neverRules = $service->activeCandidatesForCode(
        $this->user->business_id,
        'FIRSTVISIT',
        CarbonImmutable::parse('2026-09-18'),
        $this->user->default_branch_id,
    );

    expect($service->resolveFromCandidates($sale, $item, $neverRules, [
        'lifetime_value' => 0,
        'days_since_last_visit' => null,
    ]))->not->toBeNull();

    expect($service->resolveFromCandidates($sale, $item, $neverRules, [
        'lifetime_value' => 0,
        'days_since_last_visit' => 1,
    ]))->toBeNull();
});

test('invalid customer criteria ranges are rejected', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'min_lifetime_value' => 10000,
            'max_lifetime_value' => 5000,
        ]))
        ->assertStatus(422);

    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', discountRulePayload([
            'last_visit_operator' => 'BETWEEN',
            'last_visit_days_min' => 60,
            'last_visit_days_max' => 30,
        ]))
        ->assertStatus(422);
});
