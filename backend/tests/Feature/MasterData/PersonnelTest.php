<?php

use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

function personnelPayload(array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

test('managing partner is stored as partner without forcing profit sharing eligibility', function () {
    $user = foundationUser('ROLE-SA');

    $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', personnelPayload([
            'first_name' => 'Alex',
            'last_name' => 'Partner',
            'position_code' => 'MANAGING_PARTNER',
            'employment_type_code' => null,
            'personnel_type_code' => 'EMPLOYEE',
            'profit_sharing_eligible' => false,
            'hourly_rate' => 0,
            'compensation_rule_code' => 'NONE',
            'can_perform_services' => false,
            'commission_eligible' => false,
            'hire_date' => '2026-09-01',
        ]))
        ->assertCreated()
        ->assertJsonPath('personnel.personnel_type.code', 'PARTNER')
        ->assertJsonPath('personnel.position.code', 'MANAGING_PARTNER')
        ->assertJsonPath('personnel.profit_sharing_eligible', false);

    expect(Personnel::query()->count())->toBe(1);
});

test('partner can be configured for profit sharing independently from operational compensation', function () {
    $user = foundationUser('ROLE-SA');

    $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', personnelPayload([
            'first_name' => 'Taylor',
            'last_name' => 'Partner',
            'position_code' => 'MANAGING_PARTNER',
            'employment_type_code' => null,
            'personnel_type_code' => 'PARTNER',
            'profit_sharing_eligible' => true,
            'profit_sharing_percentage' => 20,
            'profit_sharing_effective_from' => '2026-09-01',
            'hourly_rate' => 0,
            'compensation_rule_code' => 'NONE',
            'can_perform_services' => true,
            'commission_eligible' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('personnel.personnel_type.code', 'PARTNER')
        ->assertJsonPath('personnel.profit_sharing_eligible', true)
        ->assertJsonPath('personnel.profit_sharing_percentage', 20);
});

test('ot entitled personnel requires a multiplier', function () {
    $user = foundationUser('ROLE-SA');

    $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', personnelPayload([
            'ot_entitled' => true,
            'ot_multiplier' => null,
        ]))
        ->assertUnprocessable();
});

test('profit sharing percentage accepts whole and decimal percentages', function () {
    $user = foundationUser('ROLE-SA');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', personnelPayload([
            'first_name' => 'Profit',
            'last_name' => 'Partner',
            'position_code' => 'MANAGING_PARTNER',
            'employment_type_code' => null,
            'personnel_type_code' => 'PARTNER',
            'profit_sharing_eligible' => true,
            'profit_sharing_percentage' => 75,
            'profit_sharing_effective_from' => '2026-09-01',
            'hourly_rate' => 0,
            'compensation_rule_code' => 'NONE',
            'can_perform_services' => false,
            'commission_eligible' => false,
        ]))
        ->assertCreated()
        ->assertJsonPath('personnel.profit_sharing_percentage', 75);

    $id = $response->json('personnel.id');

    $this->actingAs($user)
        ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
            'first_name' => 'Profit',
            'last_name' => 'Partner',
            'position_code' => 'MANAGING_PARTNER',
            'employment_type_code' => null,
            'personnel_type_code' => 'PARTNER',
            'profit_sharing_eligible' => true,
            'profit_sharing_percentage' => 25.5,
            'profit_sharing_effective_from' => '2026-09-01',
            'hourly_rate' => 0,
            'compensation_rule_code' => 'NONE',
            'can_perform_services' => false,
            'commission_eligible' => false,
        ]))
        ->assertOk()
        ->assertJsonPath('personnel.profit_sharing_percentage', 25.5);
});

test('future compensation effective date is allowed while locked payroll history stays protected', function () {
    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-09-19 12:00:00', 'Asia/Manila'));
    try {
        $user = foundationUser('ROLE-SA');
        $created = $this->actingAs($user)
            ->postJson('/api/v1/hr/personnel', personnelPayload([
                'compensation_effective_from' => '2026-09-19',
            ]))
            ->assertCreated();

        $id = $created->json('personnel.id');

        $this->actingAs($user)
            ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
                'hourly_rate' => 150,
                'compensation_effective_from' => '2026-10-01',
            ]))
            ->assertOk()
            ->assertJsonPath('personnel.compensation_effective_from', '2026-10-01');
    } finally {
        \Carbon\CarbonImmutable::setTestNow();
    }
});


test('pending commission activity sets the earliest compensation change date', function () {
    $user = foundationUser('ROLE-SA');
    $created = $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', personnelPayload([
            'compensation_effective_from' => '2026-09-01',
        ]))
        ->assertCreated();

    $id = $created->json('personnel.id');
    $personnel = Personnel::query()->findOrFail($id);

    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $user->business_id,
        'branch_id' => $user->default_branch_id,
        'code' => 'SALE-TEST-PENDING-COMMISSION-001',
        'visit_id' => null,
        'booking_id' => null,
        'customer_id' => null,
        'sale_channel' => 'OPERATIONS',
        'sale_date' => '2026-09-21 12:00:00',
        'gross_amount' => 1000,
        'advance_payments' => 0,
        'checkout_payments' => 1000,
        'total_paid' => 1000,
        'outstanding_balance' => 0,
        'status' => 'PAID',
        'financial_finalized_at' => '2026-09-21 12:00:00',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'code' => 'SALEITEM-TEST-PENDING-COMMISSION-001',
        'source_key' => 'TEST-PENDING-COMMISSION-001',
        'visit_service_line_id' => null,
        'visit_retail_line_id' => null,
        'service_id' => null,
        'assigned_personnel_id' => $personnel->id,
        'assigned_personnel_name_snapshot' => $personnel->full_name,
        'item_type' => 'SERVICE',
        'item_name_snapshot' => 'Pending Commission Test',
        'quantity' => 1,
        'unit_price' => 1000,
        'line_total' => 1000,
        'status' => 'PAID',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('commission_earnings')->insert([
        'business_id' => $user->business_id,
        'code' => 'COM-TEST-PENDING-001',
        'sale_id' => $saleId,
        'sale_item_id' => $saleItemId,
        'visit_id' => null,
        'visit_service_line_id' => null,
        'visit_retail_line_id' => null,
        'earning_type' => 'SERVICE',
        'booking_id' => null,
        'customer_id' => null,
        'service_id' => null,
        'personnel_id' => $personnel->id,
        'commission_rule_id' => null,
        'work_date' => '2026-09-21',
        'service_name_snapshot' => 'Pending Commission Test',
        'personnel_name_snapshot' => $personnel->full_name,
        'basis' => 'STANDARD_SERVICE_PRICE',
        'basis_amount' => 1000,
        'calculation_type' => 'FIXED',
        'rate_value' => 100,
        'commission_amount' => 100,
        'status' => 'OPEN',
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/hr/personnel')
        ->assertOk()
        ->assertJsonPath('personnel.0.compensation_effective_date_control.blocked_through', '2026-09-21')
        ->assertJsonPath('personnel.0.compensation_effective_date_control.earliest_effective_date', '2026-09-22');

    $this->actingAs($user)
        ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
            'hourly_rate' => 150,
            'compensation_effective_from' => '2026-09-21',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Compensation activity already exists through 2026-09-21. New compensation terms must take effect on or after 2026-09-22.');

    $this->actingAs($user)
        ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
            'hourly_rate' => 150,
            'compensation_effective_from' => '2026-09-22',
        ]))
        ->assertOk()
        ->assertJsonPath('personnel.compensation_effective_from', '2026-09-22');
});

test('initial compensation term uses hire date and same date changes update the term instead of duplicating it', function () {
    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Manila'));
    try {
        $user = foundationUser('ROLE-SA');
        $created = $this->actingAs($user)
            ->postJson('/api/v1/hr/personnel', personnelPayload([
                'hire_date' => '2026-09-01',
                'compensation_effective_from' => null,
                'ot_entitled' => true,
                'ot_multiplier' => 1.25,
            ]))
            ->assertCreated();

        $id = $created->json('personnel.id');

        expect(DB::table('personnel_compensation_terms')->where('personnel_id', $id)->count())->toBe(1)
            ->and((string) DB::table('personnel_compensation_terms')->where('personnel_id', $id)->value('effective_from'))
            ->toStartWith('2026-09-01');

        $this->actingAs($user)
            ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
                'hire_date' => '2026-09-01',
                'hourly_rate' => 150,
                'ot_entitled' => true,
                'ot_multiplier' => 0.75,
                'compensation_effective_from' => '2026-09-18',
            ]))
            ->assertOk();

        expect(DB::table('personnel_compensation_terms')->where('personnel_id', $id)->count())->toBe(2)
            ->and(DB::table('personnel_compensation_terms')
                ->where('personnel_id', $id)
                ->whereDate('effective_from', '2026-09-18')
                ->count())->toBe(1);

        $this->actingAs($user)
            ->putJson('/api/v1/hr/personnel/'.$id, personnelPayload([
                'hire_date' => '2026-09-01',
                'hourly_rate' => 175,
                'ot_entitled' => true,
                'ot_multiplier' => 0.5,
                'compensation_effective_from' => '2026-09-18',
            ]))
            ->assertOk();

        expect(DB::table('personnel_compensation_terms')->where('personnel_id', $id)->count())->toBe(2)
            ->and((float) DB::table('personnel_compensation_terms')
                ->where('personnel_id', $id)
                ->whereDate('effective_from', '2026-09-18')
                ->value('hourly_rate'))->toBe(175.0);
    } finally {
        \Carbon\CarbonImmutable::setTestNow();
    }
});

