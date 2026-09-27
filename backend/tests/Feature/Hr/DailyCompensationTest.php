<?php

use App\Models\Attendance;
use App\Models\DailyCompensation;
use App\Models\Personnel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\AttendancePermissionSeeder::class);
    $this->admin = foundationUser('ROLE-SA');

    $response = $this->actingAs($this->admin)->postJson('/api/v1/hr/personnel', [
        'first_name' => 'Daily', 'last_name' => 'Barber', 'mobile_number' => null, 'email' => null,
        'position_code' => 'BARBER', 'branch_id' => null, 'employment_type_code' => 'REGULAR',
        'personnel_type_code' => 'EMPLOYEE', 'profit_sharing_eligible' => false,
        'profit_sharing_percentage' => 0, 'profit_sharing_effective_from' => null,
        'profit_sharing_effective_until' => null, 'hourly_rate' => 100, 'ot_entitled' => true,
        'ot_multiplier' => 1.25, 'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
        'guaranteed_daily_pay' => 0, 'can_perform_services' => true, 'commission_eligible' => true,
        'hire_date' => '2026-09-01', 'linked_user_id' => null, 'status' => 'ACTIVE', 'notes' => null,
    ])->assertCreated();
    $this->personnel = Personnel::query()->findOrFail($response->json('personnel.id'));

    $weekly = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day, 'is_working_day' => $day <= 6,
        'expected_minutes' => $day <= 6 ? 480 : 0, 'start_time' => null, 'end_time' => null,
    ])->all();
    $this->actingAs($this->admin)->putJson('/api/v1/hr/personnel/'.$this->personnel->id.'/work-schedule', [
        'weekly' => $weekly, 'overrides' => [],
    ])->assertOk();
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('approved attendance feeds hourly and overtime daily compensation', function () {
    Attendance::query()->create([
        'business_id' => $this->admin->business_id, 'personnel_id' => $this->personnel->id,
        'branch_id' => $this->admin->default_branch_id, 'attendance_date' => '2026-09-18',
        'source' => 'ADMIN', 'workflow_status' => 'APPROVED', 'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-18 09:00:00', 'time_out_at' => '2026-09-18 18:00:00',
        'worked_minutes' => 540, 'ot_minutes' => 60,
        'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->postJson('/api/v1/hr/daily-compensation/rebuild', [
        'from' => '2026-09-18', 'to' => '2026-09-18', 'personnel_id' => $this->personnel->id,
    ])->assertOk();

    $row = DailyCompensation::query()->where('personnel_id', $this->personnel->id)->firstOrFail();
    expect((float) $row->hourly_basic_pay)->toBe(800.0)
        ->and((float) $row->ot_pay)->toBe(125.0)
        ->and((float) $row->gross_daily_projection)->toBe(925.0)
        ->and($row->status)->toBe('OPEN');
});

test('overtime calculation honors positive multipliers below one', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/hr/personnel/'.$this->personnel->id, [
        'first_name' => $this->personnel->first_name,
        'last_name' => $this->personnel->last_name,
        'mobile_number' => $this->personnel->mobile_number,
        'email' => $this->personnel->email,
        'position_code' => $this->personnel->position->code,
        'branch_id' => $this->personnel->branch_id,
        'employment_type_code' => $this->personnel->employmentType?->code,
        'personnel_type_code' => $this->personnel->personnelType->code,
        'profit_sharing_eligible' => false,
        'profit_sharing_percentage' => 0,
        'profit_sharing_effective_from' => null,
        'profit_sharing_effective_until' => null,
        'hourly_rate' => 100,
        'ot_entitled' => true,
        'ot_multiplier' => 0.75,
        'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
        'guaranteed_daily_pay' => 0,
        'compensation_effective_from' => '2026-09-18',
        'can_perform_services' => true,
        'commission_eligible' => true,
        'paid_leave_entitled' => true,
        'hire_date' => '2026-09-01',
        'linked_user_id' => null,
        'status' => 'ACTIVE',
        'notes' => null,
    ])->assertOk();

    Attendance::query()->create([
        'business_id' => $this->admin->business_id, 'personnel_id' => $this->personnel->id,
        'branch_id' => $this->admin->default_branch_id, 'attendance_date' => '2026-09-18',
        'source' => 'ADMIN', 'workflow_status' => 'APPROVED', 'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-18 09:00:00', 'time_out_at' => '2026-09-18 18:00:00',
        'worked_minutes' => 540, 'ot_minutes' => 60,
        'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->postJson('/api/v1/hr/daily-compensation/rebuild', [
        'from' => '2026-09-18', 'to' => '2026-09-18', 'personnel_id' => $this->personnel->id,
    ])->assertOk();

    $row = DailyCompensation::query()->where('personnel_id', $this->personnel->id)->firstOrFail();
    expect((float) $row->ot_pay)->toBe(75.0);
});
