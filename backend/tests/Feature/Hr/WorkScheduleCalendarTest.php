<?php

use App\Models\Attendance;
use App\Models\Personnel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\AttendancePermissionSeeder::class);

    $this->admin = foundationUser('ROLE-SA');
    $this->barber = foundationUser('ROLE-BAR', [
        'username' => 'calendar.barber',
        'display_name' => 'Calendar Barber',
        'name' => 'Calendar Barber',
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/hr/personnel', [
        'first_name' => 'Calendar', 'last_name' => 'Barber', 'mobile_number' => null, 'email' => null,
        'position_code' => 'BARBER', 'branch_id' => null, 'employment_type_code' => 'REGULAR',
        'personnel_type_code' => 'EMPLOYEE', 'profit_sharing_eligible' => false,
        'profit_sharing_percentage' => 0, 'profit_sharing_effective_from' => null,
        'profit_sharing_effective_until' => null, 'hourly_rate' => 120, 'ot_entitled' => false,
        'ot_multiplier' => null, 'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
        'guaranteed_daily_pay' => 0, 'can_perform_services' => true, 'commission_eligible' => true,
        'hire_date' => '2026-09-01', 'linked_user_id' => $this->barber->id, 'status' => 'ACTIVE', 'notes' => null,
    ])->assertCreated();

    $this->personnel = Personnel::query()->findOrFail($response->json('personnel.id'));
});

afterEach(function () { CarbonImmutable::setTestNow(); });

function weeklySchedulePayload(): array
{
    return collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'is_working_day' => $day <= 6,
        'expected_minutes' => $day <= 6 ? 480 : 0,
        'start_time' => null,
        'end_time' => null,
    ])->all();
}

test('admin can configure weekly schedule and overrides', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/hr/personnel/'.$this->personnel->id.'/work-schedule', [
            'weekly' => weeklySchedulePayload(),
            'overrides' => [[
                'schedule_date' => '2026-09-17',
                'schedule_type' => 'DAY_OFF',
                'expected_minutes' => 0,
                'start_time' => null,
                'end_time' => null,
                'notes' => 'Approved day off',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('schedule.configured', true)
        ->assertJsonPath('schedule.overrides.0.schedule_type', 'DAY_OFF');
});

test('calendar derives absent day off partial and present from schedule plus approved attendance', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/hr/personnel/'.$this->personnel->id.'/work-schedule', [
        'weekly' => weeklySchedulePayload(),
        'overrides' => [[
            'schedule_date' => '2026-09-17',
            'schedule_type' => 'DAY_OFF',
            'expected_minutes' => 0,
            'start_time' => null,
            'end_time' => null,
            'notes' => null,
        ]],
    ])->assertOk();

    Attendance::query()->create([
        'business_id' => $this->admin->business_id,
        'personnel_id' => $this->personnel->id,
        'attendance_date' => '2026-09-15',
        'source' => 'ADMIN',
        'workflow_status' => 'APPROVED',
        'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-15 09:00:00',
        'time_out_at' => '2026-09-15 17:00:00',
        'worked_minutes' => 480,
        'ot_minutes' => 0,
    ]);

    Attendance::query()->create([
        'business_id' => $this->admin->business_id,
        'personnel_id' => $this->personnel->id,
        'attendance_date' => '2026-09-16',
        'source' => 'ADMIN',
        'workflow_status' => 'APPROVED',
        'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-16 09:00:00',
        'time_out_at' => '2026-09-16 13:00:00',
        'worked_minutes' => 240,
        'ot_minutes' => 0,
    ]);

    $response = $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-attendance/calendar?month=2026-09')
        ->assertOk()
        ->assertJsonPath('schedule_configured', true);

    $byDate = collect($response->json('days'))->keyBy('date');
    expect($byDate['2026-09-15']['calendar_status'])->toBe('PRESENT')
        ->and($byDate['2026-09-16']['calendar_status'])->toBe('NOT_FULL_DAY')
        ->and($byDate['2026-09-17']['calendar_status'])->toBe('DAY_OFF')
        ->and($byDate['2026-09-14']['calendar_status'])->toBe('ABSENT');
});

test('unconfigured schedule does not invent absences or days off', function () {
    $response = $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-attendance/calendar?month=2026-09')
        ->assertOk()
        ->assertJsonPath('schedule_configured', false);

    $byDate = collect($response->json('days'))->keyBy('date');
    expect($byDate['2026-09-14']['calendar_status'])->toBe('UNSCHEDULED');
});
