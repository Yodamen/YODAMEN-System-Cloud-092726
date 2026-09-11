<?php

use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

test('managing partner is stored as partner and profit sharing eligible', function () {
    $user = foundationUser('ROLE-SA');

    $this->actingAs($user)
        ->postJson('/api/v1/hr/personnel', [
            'first_name' => 'Alex',
            'last_name' => 'Partner',
            'mobile_number' => null,
            'email' => null,
            'position_code' => 'MANAGING_PARTNER',
            'branch_id' => null,
            'employment_type_code' => null,
            'personnel_type_code' => 'EMPLOYEE',
            'profit_sharing_eligible' => false,
            'profit_sharing_percentage' => 20,
            'profit_sharing_effective_from' => '2026-09-01',
            'profit_sharing_effective_until' => null,
            'hourly_rate' => 0,
            'ot_entitled' => false,
            'ot_multiplier' => null,
            'compensation_rule_code' => 'NONE',
            'guaranteed_daily_pay' => 0,
            'can_perform_services' => false,
            'commission_eligible' => false,
            'hire_date' => '2026-09-01',
            'linked_user_id' => null,
            'status' => 'ACTIVE',
            'notes' => null,
        ])
        ->assertCreated()
        ->assertJsonPath('personnel.personnel_type.code', 'PARTNER')
        ->assertJsonPath('personnel.profit_sharing_eligible', true);

    expect(Personnel::query()->count())->toBe(1);
});

test('ot entitled personnel requires a multiplier', function () {
    $user = foundationUser('ROLE-SA');

    $this->actingAs($user)
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
            'ot_entitled' => true,
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
        ->assertUnprocessable();
});
