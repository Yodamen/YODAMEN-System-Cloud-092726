<?php

use App\Models\Attendance;
use App\Models\Personnel;
use App\Models\PersonnelWorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\AttendancePermissionSeeder::class);

    $this->superAdmin = foundationUser('ROLE-SA');
    $this->barberUser = foundationUser('ROLE-BAR', [
        'username' => 'attendance.barber',
        'display_name' => 'Attendance Barber',
        'name' => 'Attendance Barber',
    ]);

    $response = $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/hr/personnel', [
            'first_name' => 'Attendance',
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
            'ot_multiplier' => 1.25,
            'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
            'guaranteed_daily_pay' => 0,
            'can_perform_services' => true,
            'commission_eligible' => true,
            'hire_date' => '2026-09-01',
            'linked_user_id' => $this->barberUser->id,
            'status' => 'ACTIVE',
            'notes' => null,
        ])
        ->assertCreated();

    $this->personnel = Personnel::query()->findOrFail($response->json('personnel.id'));

    PersonnelWorkSchedule::query()->create([
        'business_id' => $this->superAdmin->business_id,
        'personnel_id' => $this->personnel->id,
        'day_of_week' => 5,
        'is_working_day' => true,
        'expected_minutes' => 480,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'created_by' => $this->superAdmin->id,
        'updated_by' => $this->superAdmin->id,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function attendanceProof(string $name = 'proof.jpg'): UploadedFile
{
    return UploadedFile::fake()->create($name, 12, 'image/jpeg');
}

test('barber can time in and time out only through self service using server timestamps', function () {
    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', [
            'proof' => attendanceProof('time-in.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('attendance.workflow_status', 'WORKING')
        ->assertJsonPath('attendance.attendance_status', 'PENDING')
        ->assertJsonPath('attendance.time_in_value', '09:00')
        ->assertJsonPath('attendance.proofs.time_in', true);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 18:30:00', 'Asia/Manila'));

    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-out', [
            'proof' => attendanceProof('time-out.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('attendance.workflow_status', 'COMPLETED')
        ->assertJsonPath('attendance.attendance_status', 'PENDING')
        ->assertJsonPath('attendance.time_out_value', '18:30')
        ->assertJsonPath('attendance.worked_hours', 9.5)
        ->assertJsonPath('attendance.ot_hours', 1.5)
        ->assertJsonPath('attendance.proofs.time_out', true);

    expect(Attendance::query()->count())->toBe(1);
});

test('early arrival before scheduled start is not counted as overtime', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 08:00:00', 'Asia/Manila'));
    $this->actingAs($this->barberUser)->post('/api/v1/hr/my-attendance/time-in', ['proof' => attendanceProof('early-in.jpg')], ['Accept' => 'application/json'])->assertCreated();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Manila'));
    $this->actingAs($this->barberUser)->post('/api/v1/hr/my-attendance/time-out', ['proof' => attendanceProof('late-out.jpg')], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('attendance.worked_hours', 10)
        ->assertJsonPath('attendance.ot_hours', 1);
});

test('photo proof is mandatory and duplicate time in is rejected', function () {
    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', [], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', [
            'proof' => attendanceProof(),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', [
            'proof' => attendanceProof('duplicate.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have an open Time In record. Time Out first before starting another attendance record.');
});

test('completed self service attendance remains pending until admin approval', function () {
    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', ['proof' => attendanceProof('in.jpg')], ['Accept' => 'application/json'])
        ->assertCreated();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 17:00:00', 'Asia/Manila'));

    $attendanceId = $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-out', ['proof' => attendanceProof('out.jpg')], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('attendance.id');

    $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/hr/attendance?from=2026-09-18&to=2026-09-18&status=COMPLETED')
        ->assertOk()
        ->assertJsonPath('summary.awaiting_approval', 1)
        ->assertJsonPath('rows.0.id', $attendanceId);

    $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/hr/attendance/{$attendanceId}/approve")
        ->assertOk()
        ->assertJsonPath('attendance.workflow_status', 'APPROVED')
        ->assertJsonPath('attendance.attendance_status', 'PRESENT');
});

test('admin adjustment preserves original timestamps and requires a reason', function () {
    $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-in', ['proof' => attendanceProof('in.jpg')], ['Accept' => 'application/json'])
        ->assertCreated();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 17:00:00', 'Asia/Manila'));

    $attendanceId = $this->actingAs($this->barberUser)
        ->post('/api/v1/hr/my-attendance/time-out', ['proof' => attendanceProof('out.jpg')], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('attendance.id');

    $this->actingAs($this->superAdmin)
        ->putJson("/api/v1/hr/attendance/{$attendanceId}/adjust", [
            'time_in' => '08:30',
            'time_out' => '17:30',
            'ot_hours' => 1,
            'reason' => 'Verified actual arrival and departure from branch log.',
        ])
        ->assertOk()
        ->assertJsonPath('attendance.workflow_status', 'ADJUSTED')
        ->assertJsonPath('attendance.attendance_status', 'PRESENT')
        ->assertJsonPath('attendance.time_in_value', '08:30')
        ->assertJsonPath('attendance.time_out_value', '17:30')
        ->assertJsonPath('attendance.ot_hours', 0.5);

    $attendance = Attendance::query()->findOrFail($attendanceId);

    expect($attendance->original_time_in_at)->not->toBeNull()
        ->and($attendance->original_time_out_at)->not->toBeNull()
        ->and($attendance->adjustment_reason)->toBe('Verified actual arrival and departure from branch log.');
});

test('payroll locked attendance cannot be approved or adjusted directly', function () {
    $attendance = Attendance::query()->create([
        'business_id' => $this->superAdmin->business_id,
        'personnel_id' => $this->personnel->id,
        'branch_id' => $this->barberUser->default_branch_id,
        'attendance_date' => '2026-09-18',
        'source' => 'SELF_SERVICE',
        'workflow_status' => 'COMPLETED',
        'attendance_status' => 'PENDING',
        'time_in_at' => '2026-09-18 09:00:00',
        'time_out_at' => '2026-09-18 17:00:00',
        'worked_minutes' => 480,
        'ot_minutes' => 0,
        'payroll_locked_at' => '2026-09-18 18:00:00',
        'created_by' => $this->superAdmin->id,
        'updated_by' => $this->superAdmin->id,
    ]);

    $this->actingAs($this->superAdmin)
        ->putJson("/api/v1/hr/attendance/{$attendance->id}/adjust", [
            'time_in' => '08:45',
            'time_out' => '17:00',
            'reason' => 'Correction after payroll lock.',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This attendance is already tied to payroll and cannot be changed from Attendance. Use the payroll adjustment workflow instead.');
});

test('barber default role can use my attendance but cannot open admin attendance dashboard', function () {
    $this->actingAs($this->barberUser)
        ->getJson('/api/v1/hr/my-attendance')
        ->assertOk();

    $this->actingAs($this->barberUser)
        ->getJson('/api/v1/hr/attendance')
        ->assertForbidden();
});
