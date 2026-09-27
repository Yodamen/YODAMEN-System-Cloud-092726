<?php

use App\Models\Branch;
use App\Models\CommissionRule;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Service;
use App\Services\Commissions\CommissionRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 10:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);

    $this->superAdmin = foundationUser('ROLE-SA');

    $serviceResponse = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/services', [
            'name' => 'Commission Test Haircut',
            'category_code' => 'HAIRCUT',
            'service_count_code' => 'SINGLE_SESSION',
            'number_of_sessions' => 1,
            'duration_minutes' => 45,
            'description' => 'Service fixture for commission rule testing',
            'status_code' => 'ACTIVE',
            'initial_price' => 500,
            'effective_from' => '2026-09-01',
        ])
        ->assertCreated();

    $this->commissionService = Service::query()->findOrFail($serviceResponse->json('service.id'));

    $personnelResponse = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/hr/personnel', [
            'first_name' => 'Jordan',
            'last_name' => 'Barber',
            'mobile_number' => null,
            'email' => null,
            'position_code' => 'BARBER',
            'branch_id' => null,
            'employment_type_code' => 'REGULAR',
            'personnel_type_code' => 'EMPLOYEE',
            'profit_sharing_eligible' => false,
            'profit_sharing_percentage' => 0,
            'profit_sharing_effective_from' => null,
            'profit_sharing_effective_until' => null,
            'hourly_rate' => 120,
            'ot_entitled' => false,
            'ot_multiplier' => null,
            'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
            'guaranteed_daily_pay' => 0,
            'can_perform_services' => true,
            'commission_eligible' => true,
            'hire_date' => null,
            'linked_user_id' => null,
            'status' => 'ACTIVE',
            'notes' => null,
        ])
        ->assertCreated();

    $this->employee = Personnel::query()->findOrFail($personnelResponse->json('personnel.id'));

    $categoryGroup = ReferenceGroup::query()->where('business_id', $this->superAdmin->business_id)->where('code', 'SERVICE_CATEGORY')->firstOrFail();
    $positionGroup = ReferenceGroup::query()->where('business_id', $this->superAdmin->business_id)->where('code', 'EMPLOYEE_POSITION')->firstOrFail();
    $this->haircutCategory = ReferenceValue::query()->where('reference_group_id', $categoryGroup->id)->where('code', 'HAIRCUT')->firstOrFail();
    $this->barberPosition = ReferenceValue::query()->where('reference_group_id', $positionGroup->id)->where('code', 'BARBER')->firstOrFail();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function commissionRulePayload(array $overrides = []): array
{
    return array_merge([
        'rule_name' => 'General Commission',
        'commission_scope' => 'SERVICE',
        'branch_id' => null,
        'applies_to' => 'ALL',
        'personnel_id' => null,
        'position_reference_id' => null,
        'service_id' => null,
        'service_category_reference_id' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 20,
        'effective_from' => '2026-09-17',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
        'notes' => null,
    ], $overrides);
}

test('commission rules index returns flexible form options', function () {
    $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/admin/commission-rules')
        ->assertOk()
        ->assertJsonPath('phase.posting_active', true)
        ->assertJsonPath('options.services.0.id', $this->commissionService->id);
});

test('super admin can create employee specific service percentage rule', function () {
    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Jordan Haircut 40%',
            'applies_to' => 'EMPLOYEE',
            'personnel_id' => $this->employee->id,
            'service_id' => $this->commissionService->id,
            'rate_value' => 40,
        ]))
        ->assertCreated()
        ->assertJsonPath('rule.personnel.id', $this->employee->id)
        ->assertJsonPath('rule.service.id', $this->commissionService->id)
        ->assertJsonPath('rule.rate_value', 40)
        ->assertJsonPath('rule.basis', 'STANDARD_SERVICE_PRICE')
        ->assertJsonPath('rule.specificity', 'Employee + Specific Service');

    expect(CommissionRule::query()->count())->toBe(1);
});

test('fixed amount and service category rules are supported', function () {
    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Barber Haircut Fixed',
            'applies_to' => 'POSITION',
            'position_reference_id' => $this->barberPosition->id,
            'service_category_reference_id' => $this->haircutCategory->id,
            'calculation_type' => 'FIXED',
            'rate_value' => 150,
        ]))
        ->assertCreated()
        ->assertJsonPath('rule.position.id', $this->barberPosition->id)
        ->assertJsonPath('rule.service_category.id', $this->haircutCategory->id)
        ->assertJsonPath('rule.calculation_type', 'FIXED')
        ->assertJsonPath('rule.rate_value', 150);
});

test('resolver follows employee service hierarchy before broader rules', function () {
    $general = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'General 20%',
            'rate_value' => 20,
        ]))->assertCreated()->json('rule.id');

    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Employee All 30%',
            'applies_to' => 'EMPLOYEE',
            'personnel_id' => $this->employee->id,
            'rate_value' => 30,
        ]))->assertCreated();

    $specific = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Employee Haircut 40%',
            'applies_to' => 'EMPLOYEE',
            'personnel_id' => $this->employee->id,
            'service_id' => $this->commissionService->id,
            'rate_value' => 40,
        ]))->assertCreated()->json('rule.id');

    $resolved = app(CommissionRuleService::class)->resolveFor(
        $this->superAdmin->business_id,
        $this->employee,
        $this->commissionService,
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($specific)
        ->and($resolved->id)->not->toBe($general)
        ->and((float) $resolved->rate_value)->toBe(40.0);
});

test('priority resolves overlapping rules with the same specificity', function () {
    $low = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'General Low Priority',
            'rate_value' => 20,
            'priority' => 1,
        ]))->assertCreated()->json('rule.id');

    $high = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'General High Priority',
            'rate_value' => 25,
            'priority' => 9,
        ]))->assertCreated()->json('rule.id');

    $resolved = app(CommissionRuleService::class)->resolveFor(
        $this->superAdmin->business_id,
        $this->employee,
        $this->commissionService,
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($high)
        ->and($resolved->id)->not->toBe($low);
});

test('branch specific rule wins over all branches at otherwise equal specificity', function () {
    $branch = Branch::query()->where('business_id', $this->superAdmin->business_id)->where('status', 'ACTIVE')->firstOrFail();

    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'All Branch Haircut 30%',
            'service_id' => $this->commissionService->id,
            'rate_value' => 30,
            'priority' => 99,
        ]))->assertCreated();

    $branchRule = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Branch Haircut 35%',
            'branch_id' => $branch->id,
            'service_id' => $this->commissionService->id,
            'rate_value' => 35,
            'priority' => 0,
        ]))->assertCreated()->json('rule.id');

    $resolved = app(CommissionRuleService::class)->resolveFor(
        $this->superAdmin->business_id,
        $this->employee,
        $this->commissionService,
        CarbonImmutable::parse('2026-09-17'),
        $branch->id,
    );

    expect($resolved)->not->toBeNull()->and($resolved->id)->toBe($branchRule);
});

test('commission rule can be edited without replacing its history row', function () {
    $ruleId = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Editable Rule',
            'service_id' => $this->commissionService->id,
            'rate_value' => 30,
        ]))->assertCreated()->json('rule.id');

    $this->actingAs($this->superAdmin)
        ->putJson('/api/v1/admin/commission-rules/'.$ruleId, commissionRulePayload([
            'rule_name' => 'Edited Rule',
            'service_id' => $this->commissionService->id,
            'rate_value' => 32.5,
            'priority' => 5,
        ]))
        ->assertOk()
        ->assertJsonPath('rule.rule_name', 'Edited Rule')
        ->assertJsonPath('rule.rate_value', 32.5)
        ->assertJsonPath('rule.priority', 5);

    expect(CommissionRule::query()->count())->toBe(1);
});

test('retail purchase commission rules are separate from service rules', function () {
    $retailRuleId = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/admin/commission-rules', commissionRulePayload([
            'rule_name' => 'Retail Sales 10%',
            'commission_scope' => 'RETAIL',
            'applies_to' => 'EMPLOYEE',
            'personnel_id' => $this->employee->id,
            'service_id' => null,
            'service_category_reference_id' => null,
            'rate_value' => 10,
        ]))
        ->assertCreated()
        ->assertJsonPath('rule.commission_scope', 'RETAIL')
        ->assertJsonPath('rule.basis', 'NET_RETAIL_SALE')
        ->assertJsonPath('rule.specificity', 'Employee + All Retail Products')
        ->assertJsonPath('rule.service', null)
        ->json('rule.id');

    $resolved = app(CommissionRuleService::class)->resolveRetailFor(
        $this->superAdmin->business_id,
        $this->employee,
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($retailRuleId)
        ->and($resolved->commission_scope)->toBe('RETAIL');

    $serviceResolved = app(CommissionRuleService::class)->resolveFor(
        $this->superAdmin->business_id,
        $this->employee,
        $this->commissionService,
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($serviceResolved)->toBeNull();
});

