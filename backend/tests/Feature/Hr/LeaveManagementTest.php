<?php

use App\Models\HrLeaveRequest;
use App\Models\Personnel;
use App\Models\PersonnelScheduleOverride;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\AttendancePermissionSeeder::class);
    $this->seed(\Database\Seeders\HrLeaveSeeder::class);

    $this->admin = foundationUser('ROLE-SA');
    $this->barber = foundationUser('ROLE-BAR', [
        'username' => 'leave.barber',
        'display_name' => 'Leave Barber',
        'name' => 'Leave Barber',
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/hr/personnel', [
        'first_name' => 'Leave', 'last_name' => 'Barber', 'mobile_number' => null, 'email' => null,
        'position_code' => 'BARBER', 'branch_id' => null, 'employment_type_code' => 'REGULAR',
        'personnel_type_code' => 'EMPLOYEE', 'profit_sharing_eligible' => false,
        'profit_sharing_percentage' => 0, 'profit_sharing_effective_from' => null,
        'profit_sharing_effective_until' => null, 'hourly_rate' => 120, 'ot_entitled' => false,
        'ot_multiplier' => null, 'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
        'guaranteed_daily_pay' => 0, 'can_perform_services' => true, 'commission_eligible' => true,
        'hire_date' => '2026-09-01', 'linked_user_id' => $this->barber->id, 'status' => 'ACTIVE', 'notes' => null,
    ])->assertCreated();

    $this->personnel = Personnel::query()->findOrFail($response->json('personnel.id'));

    $weekly = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'is_working_day' => $day <= 6,
        'expected_minutes' => $day <= 6 ? 480 : 0,
        'start_time' => null,
        'end_time' => null,
    ])->all();

    $this->actingAs($this->admin)->putJson('/api/v1/hr/personnel/'.$this->personnel->id.'/work-schedule', [
        'weekly' => $weekly,
        'overrides' => [],
    ])->assertOk();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('staff can file a day off and approval creates a protected schedule override', function () {
    $response = $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'DAY_OFF',
        'leave_type_id' => null,
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Family commitment.',
    ])->assertCreated()->assertJsonPath('request.status', 'PENDING');

    $id = $response->json('request.id');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hr/leave-requests/{$id}/approve", ['notes' => 'Approved.'])
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    expect(PersonnelScheduleOverride::query()
        ->where('personnel_id', $this->personnel->id)
        ->whereDate('schedule_date', '2026-09-19')
        ->where('schedule_type', 'DAY_OFF')
        ->where('source_type', 'LEAVE_REQUEST')
        ->exists())->toBeTrue();

    $calendar = $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-attendance/calendar?month=2026-09')
        ->assertOk();

    $day = collect($calendar->json('days'))->firstWhere('date', '2026-09-19');
    expect($day['calendar_status'])->toBe('DAY_OFF');
});

test('approved leave appears as leave in my attendance calendar', function () {
    $leaveTypeId = $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-leave')
        ->assertOk()
        ->json('leave_types.2.id');

    $response = $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'LEAVE',
        'leave_type_id' => $leaveTypeId,
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Emergency.',
    ])->assertCreated();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/leave-requests/'.$response->json('request.id').'/approve', ['notes' => null])
        ->assertOk();

    $calendar = $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-attendance/calendar?month=2026-09')
        ->assertOk();

    $day = collect($calendar->json('days'))->firstWhere('date', '2026-09-19');
    expect($day['calendar_status'])->toBe('LEAVE')
        ->and($day['leave']['status'])->toBe('APPROVED');
});

test('balance consuming leave cannot be approved beyond available credits', function () {
    $my = $this->actingAs($this->barber)->getJson('/api/v1/hr/my-leave')->assertOk();
    $vacation = collect($my->json('leave_types'))->firstWhere('code', 'VACATION');

    $response = $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'LEAVE',
        'leave_type_id' => $vacation['id'],
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Vacation.',
    ])->assertCreated();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/leave-requests/'.$response->json('request.id').'/approve', [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Insufficient Vacation Leave balance. Available: 0.00 day(s).');

    $this->actingAs($this->admin)
        ->putJson('/api/v1/hr/leave-balances/'.$this->personnel->id.'/'.$vacation['id'].'/2026', [
            'entitled_days' => 5,
            'adjustment_days' => 0,
            'notes' => null,
        ])->assertOk();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/leave-requests/'.$response->json('request.id').'/approve', [])
        ->assertOk()
        ->assertJsonPath('request.approved_units', 1);
});

test('staff cannot file overlapping pending requests', function () {
    $payload = [
        'request_type' => 'DAY_OFF',
        'leave_type_id' => null,
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'First.',
    ];

    $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', $payload)->assertCreated();
    $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [...$payload, 'reason' => 'Second.'])
        ->assertStatus(422);
});

test('personnel without leave entitlement cannot file leave but can still use day off', function () {
    $this->personnel->update(['paid_leave_entitled' => false]);

    $my = $this->actingAs($this->barber)->getJson('/api/v1/hr/my-leave')->assertOk();
    $vacation = collect($my->json('leave_types'))->firstWhere('code', 'VACATION');

    expect($my->json('personnel.paid_leave_entitled'))->toBeFalse()
        ->and($vacation['is_paid'])->toBeTrue()
        ->and($vacation['effective_is_paid'])->toBeFalse()
        ->and($vacation['effective_consumes_balance'])->toBeFalse();

    $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'LEAVE',
        'leave_type_id' => $vacation['id'],
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Leave while not entitled.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This personnel record is not entitled to leave.');

    $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'DAY_OFF',
        'leave_type_id' => null,
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Reschedule regular day off.',
    ])->assertCreated();

    $this->actingAs($this->admin)
        ->putJson('/api/v1/hr/leave-balances/'.$this->personnel->id.'/'.$vacation['id'].'/2026', [
            'entitled_days' => 5,
            'adjustment_days' => 0,
            'notes' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This personnel record is not entitled to paid leave. Enable Paid Leave Entitled under Personnel first.');
});

test('day off self service follows my attendance access even when my leave permission is absent', function () {
    $myLeavePermissionIds = \App\Models\Permission::query()
        ->where('module', 'my-leave')
        ->pluck('id');

    $this->barber->role->permissions()->detach($myLeavePermissionIds);
    $this->barber->refresh();

    expect($this->barber->hasPermission('my-attendance', 'VIEW'))->toBeTrue()
        ->and($this->barber->hasPermission('my-leave', 'VIEW'))->toBeFalse();

    $this->actingAs($this->barber)
        ->getJson('/api/v1/hr/my-leave')
        ->assertOk();

    $this->actingAs($this->barber)->postJson('/api/v1/hr/my-leave', [
        'request_type' => 'DAY_OFF',
        'leave_type_id' => null,
        'start_date' => '2026-09-19',
        'end_date' => '2026-09-19',
        'duration_type' => 'FULL_DAY',
        'reason' => 'Day off should remain available from My Attendance.',
    ])->assertCreated();
});

