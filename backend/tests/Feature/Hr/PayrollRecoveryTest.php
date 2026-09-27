<?php

use App\Models\Attendance;
use App\Models\Customer;
use App\Models\PayrollRecovery;
use App\Models\PayrollRecoveryAllocation;
use App\Models\PayrollRun;
use App\Models\Personnel;
use App\Models\PersonnelWorkSchedule;
use App\Models\Sale;
use App\Models\TransactionActionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\PayrollPermissionSeeder::class);
    $this->seed(\Database\Seeders\HrCompletionPermissionSeeder::class);
    $this->admin = foundationUser('ROLE-SA');

    $response = $this->actingAs($this->admin)->postJson('/api/v1/hr/personnel', [
        'first_name' => 'Recovery', 'last_name' => 'Barber', 'mobile_number' => null, 'email' => null,
        'position_code' => 'BARBER', 'branch_id' => null, 'employment_type_code' => 'REGULAR',
        'personnel_type_code' => 'EMPLOYEE', 'profit_sharing_eligible' => false,
        'profit_sharing_percentage' => 0, 'profit_sharing_effective_from' => null,
        'profit_sharing_effective_until' => null, 'hourly_rate' => 100, 'ot_entitled' => true,
        'ot_multiplier' => 1.25, 'compensation_rule_code' => 'HOURLY_PLUS_COMMISSION',
        'guaranteed_daily_pay' => 0, 'can_perform_services' => true, 'commission_eligible' => true,
        'hire_date' => '2026-09-01', 'linked_user_id' => null, 'status' => 'ACTIVE', 'notes' => null,
    ])->assertCreated();
    $this->personnel = Personnel::query()->findOrFail($response->json('personnel.id'));

    PersonnelWorkSchedule::query()->create([
        'business_id' => $this->admin->business_id,
        'personnel_id' => $this->personnel->id,
        'day_of_week' => 5,
        'is_working_day' => true,
        'expected_minutes' => 480,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
});

function createRecoveryAttendance($test, string $date, int $workedMinutes = 480): Attendance
{
    $hours = intdiv($workedMinutes, 60);
    $minutes = $workedMinutes % 60;
    $end = sprintf('%02d:%02d:00', 9 + $hours, $minutes);

    return Attendance::query()->create([
        'business_id' => $test->admin->business_id,
        'personnel_id' => $test->personnel->id,
        'attendance_date' => $date,
        'source' => 'ADMIN_ADJUSTMENT',
        'workflow_status' => 'APPROVED',
        'attendance_status' => 'PRESENT',
        'time_in_at' => $date.' 09:00:00',
        'time_out_at' => $date.' '.$end,
        'worked_minutes' => $workedMinutes,
        'ot_minutes' => 0,
        'approved_by' => $test->admin->id,
        'approved_at' => $date.' '.$end,
        'created_by' => $test->admin->id,
        'updated_by' => $test->admin->id,
    ]);
}

function createOpenPayrollRecovery($test, float $amount, string $code = 'PYR-TEST-001'): PayrollRecovery
{
    return PayrollRecovery::query()->create([
        'business_id' => $test->admin->business_id,
        'code' => $code,
        'personnel_id' => $test->personnel->id,
        'recovery_type' => 'COMMISSION',
        'origin_work_date' => '2026-09-10',
        'source_label' => 'Historical service commission',
        'recovery_amount' => $amount,
        'applied_amount' => 0,
        'remaining_amount' => $amount,
        'status' => 'OPEN',
        'reason' => 'Post-payroll transaction reversal',
        'created_by' => $test->admin->id,
    ]);
}

test('open post-payroll recovery is automatically picked up and carries forward without negative net pay', function () {
    createRecoveryAttendance($this, '2026-09-18');
    $recovery = createOpenPayrollRecovery($this, 1200);

    $first = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated()
        ->assertJsonPath('run.lines.0.payroll_recovery_deductions', 800)
        ->assertJsonPath('run.lines.0.net_pay', 0);

    $firstRunId = $first->json('run.id');
    expect(PayrollRecoveryAllocation::query()->where('payroll_run_id', $firstRunId)->where('status', 'RESERVED')->sum('amount'))->toEqual('800.00');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$firstRunId}/approve")
        ->assertOk()->assertJsonPath('run.status', 'APPROVED');

    $recovery->refresh();
    expect((float) $recovery->applied_amount)->toBe(800.0)
        ->and((float) $recovery->remaining_amount)->toBe(400.0)
        ->and($recovery->status)->toBe('PARTIAL');

    // A zero-pay payroll still closes without inventing a cash/bank disbursement.
    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$firstRunId}/mark-paid")
        ->assertOk()->assertJsonPath('run.status', 'PAID')->assertJsonPath('run.net_pay', 0);

    createRecoveryAttendance($this, '2026-09-25');
    $second = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-25', 'period_end' => '2026-09-25', 'pay_date' => '2026-09-26',
    ])->assertCreated()
        ->assertJsonPath('run.lines.0.payroll_recovery_deductions', 400)
        ->assertJsonPath('run.lines.0.net_pay', 400);

    $secondRunId = $second->json('run.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$secondRunId}/approve")->assertOk();

    $recovery->refresh();
    expect((float) $recovery->applied_amount)->toBe(1200.0)
        ->and((float) $recovery->remaining_amount)->toBe(0.0)
        ->and($recovery->status)->toBe('RECOVERED');
});

test('new recovery after payroll calculation makes the draft stale until recalculated', function () {
    createRecoveryAttendance($this, '2026-09-18');

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated()->json('run.id');

    $recovery = createOpenPayrollRecovery($this, 200, 'PYR-TEST-STALE');
    $run = PayrollRun::query()->findOrFail($runId);
    $run->update(['calculated_at' => $recovery->created_at->copy()->subSecond()]);

    $show = $this->actingAs($this->admin)->getJson("/api/v1/hr/payroll/{$runId}")->assertOk();
    expect(collect($show->json('run.approval_exceptions'))->pluck('code')->all())->toContain('DRAFT_OUT_OF_DATE');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Payroll cannot be approved: Payroll sources changed after the last calculation, including transaction corrections/reversals where applicable. Recalculate the Payroll Draft before approval.']);

    $recalculated = $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/recalculate")
        ->assertOk()
        ->assertJsonPath('run.lines.0.payroll_recovery_deductions', 200)
        ->assertJsonPath('run.lines.0.net_pay', 600);

    $after = $this->actingAs($this->admin)->getJson("/api/v1/hr/payroll/{$runId}")->assertOk();
    expect(collect($after->json('run.approval_exceptions'))->pluck('code')->all())->not->toContain('DRAFT_OUT_OF_DATE');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertOk()->assertJsonPath('run.status', 'APPROVED');
});

test('cancelled payroll draft releases recovery reservation without consuming the recovery balance', function () {
    createRecoveryAttendance($this, '2026-09-18');
    $recovery = createOpenPayrollRecovery($this, 250, 'PYR-TEST-CANCEL');

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated()->assertJsonPath('run.lines.0.payroll_recovery_deductions', 250)->json('run.id');

    expect(PayrollRecoveryAllocation::query()->where('payroll_run_id', $runId)->where('status', 'RESERVED')->count())->toBe(1);

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/cancel")
        ->assertOk()->assertJsonPath('run.status', 'CANCELLED');

    $recovery->refresh();
    expect(PayrollRecoveryAllocation::query()->where('payroll_run_id', $runId)->count())->toBe(0)
        ->and((float) $recovery->applied_amount)->toBe(0.0)
        ->and((float) $recovery->remaining_amount)->toBe(250.0)
        ->and($recovery->status)->toBe('OPEN');
});


test('posted operational booking correction makes a draft stale even when it creates no commission recovery', function () {
    createRecoveryAttendance($this, '2026-09-18');

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated()->json('run.id');

    $run = PayrollRun::query()->findOrFail($runId);
    // Keep the synthetic correction in the real-world timeline: the correction is
    // posted after the prior payroll calculation, while the later Recalculate call
    // occurs after the correction. A future-dated fixture would remain stale by design.
    $postedAt = now()->subSecond();
    $run->update(['calculated_at' => $postedAt->copy()->subSecond()]);

    $branch = \App\Models\Branch::query()->where('business_id', $this->admin->business_id)->firstOrFail();
    $customer = Customer::query()->create([
        'business_id' => $this->admin->business_id,
        'code' => 'CUS-PAYROLL-STALE-001',
        'first_name' => 'Payroll',
        'last_name' => 'Control',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    $booking = \App\Models\Booking::query()->create([
        'business_id' => $this->admin->business_id,
        'branch_id' => $branch->id,
        'code' => 'BKG-PAYROLL-STALE-001',
        'customer_id' => $customer->id,
        'customer_type' => 'NEW',
        'booking_source' => 'RESERVATION',
        'booking_date' => '2026-09-18',
        'booking_time' => '10:00:00',
        'status' => 'COMPLETED',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    $sale = Sale::query()->create([
        'business_id' => $this->admin->business_id,
        'branch_id' => $branch->id,
        'code' => 'SAL-PAYROLL-STALE-001',
        'booking_id' => $booking->id,
        'sale_channel' => 'OPERATIONS',
        'sale_date' => '2026-09-18 12:00:00',
        'status' => 'PAID',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    TransactionActionRequest::query()->create([
        'business_id' => $this->admin->business_id,
        'branch_id' => $branch->id,
        'sale_id' => $sale->id,
        'code' => 'TAR-PAYROLL-STALE-001',
        'action_type' => 'CORRECTION',
        'status' => 'POSTED',
        'reason_code' => 'OTHER',
        'reason_label' => 'Operational correction with no commission impact',
        'remarks' => 'Operational correction posted after payroll calculation.',
        'original_snapshot' => [],
        'sale_version_hash' => hash('sha256', 'SAL-PAYROLL-STALE-001'),
        'requested_by' => $this->admin->id,
        'requested_at' => $postedAt,
        'reviewed_by' => $this->admin->id,
        'reviewed_at' => $postedAt,
        'posted_by' => $this->admin->id,
        'posted_at' => $postedAt,
    ]);

    $show = $this->actingAs($this->admin)->getJson("/api/v1/hr/payroll/{$runId}")->assertOk();
    expect(collect($show->json('run.approval_exceptions'))->pluck('code')->all())->toContain('DRAFT_OUT_OF_DATE');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertStatus(422);

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/recalculate")
        ->assertOk();

    $after = $this->actingAs($this->admin)->getJson("/api/v1/hr/payroll/{$runId}")->assertOk();
    expect(collect($after->json('run.approval_exceptions'))->pluck('code')->all())->not->toContain('DRAFT_OUT_OF_DATE');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertOk()
        ->assertJsonPath('run.status', 'APPROVED');
});
