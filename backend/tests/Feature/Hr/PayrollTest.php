<?php

use App\Models\AccountingJournalEntry;
use App\Models\Attendance;
use App\Models\ChartOfAccount;
use App\Models\DailyCompensation;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeCashAdvanceLedger;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentSplit;
use App\Models\PayrollCashAdvanceWaiver;
use App\Models\PayrollRun;
use App\Models\Personnel;
use App\Models\PersonnelWorkSchedule;
use App\Models\ReferenceValue;
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
        'first_name' => 'Payroll', 'last_name' => 'Barber', 'mobile_number' => null, 'email' => null,
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

function createApprovedPayrollAttendance($test): Attendance
{
    return Attendance::query()->create([
        'business_id' => $test->admin->business_id,
        'personnel_id' => $test->personnel->id,
        'attendance_date' => '2026-09-18',
        'source' => 'ADMIN_ADJUSTMENT',
        'workflow_status' => 'APPROVED',
        'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-18 09:00:00',
        'time_out_at' => '2026-09-18 17:00:00',
        'worked_minutes' => 480,
        'ot_minutes' => 0,
        'approved_by' => $test->admin->id,
        'approved_at' => '2026-09-18 17:01:00',
        'created_by' => $test->admin->id,
        'updated_by' => $test->admin->id,
    ]);
}

test('draft payroll reserves sources and can be recalculated without creating a second run', function () {
    createApprovedPayrollAttendance($this);

    $create = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-10-01',
    ])->assertCreated()->assertJsonPath('run.net_pay', 800);

    $id = $create->json('run.id');
    expect(PayrollRun::query()->count())->toBe(1)
        ->and(DailyCompensation::query()->firstOrFail()->status)->toBe('IN_PAYROLL_DRAFT');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$id}/recalculate")
        ->assertOk()->assertJsonPath('run.id', $id);

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollLine::query()->count())->toBe(1);
});

test('approved payroll applies cash advance deduction and becomes immutable from draft actions', function () {
    createApprovedPayrollAttendance($this);

    $advance = EmployeeCashAdvance::query()->create([
        'business_id' => $this->admin->business_id, 'code' => 'CA-TEST-001', 'personnel_id' => $this->personnel->id,
        'issued_date' => '2026-09-01', 'original_amount' => 1000, 'deduction_per_payroll' => 300,
        'purpose' => 'Test', 'status' => 'ACTIVE', 'created_by' => $this->admin->id,
    ]);
    EmployeeCashAdvanceLedger::query()->create([
        'business_id' => $this->admin->business_id, 'cash_advance_id' => $advance->id,
        'entry_date' => '2026-09-01', 'entry_type' => 'ISSUANCE', 'amount' => 1000,
        'created_by' => $this->admin->id,
    ]);

    $id = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-10-01',
    ])->assertCreated()->assertJsonPath('run.cash_advance_deductions', 300)->json('run.id');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$id}/approve")
        ->assertOk()->assertJsonPath('run.status', 'APPROVED');

    expect((float) EmployeeCashAdvanceLedger::query()->where('entry_type', 'PAYROLL_DEDUCTION')->sum('amount'))->toBe(-300.0);

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$id}/recalculate")->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$id}/mark-paid")
        ->assertOk()->assertJsonPath('run.status', 'PAID');
});

test('my payroll only returns approved or paid lines linked to the logged in personnel', function () {
    $barber = foundationUser('ROLE-BAR');
    $this->personnel->update(['linked_user_id' => $barber->id]);

    $run = PayrollRun::query()->create([
        'business_id' => $this->admin->business_id, 'code' => 'PAY-TEST-0001', 'period_start' => '2026-09-01',
        'period_end' => '2026-09-15', 'pay_date' => '2026-09-16', 'status' => 'APPROVED',
        'gross_compensation' => 1000, 'net_pay' => 900, 'created_by' => $this->admin->id,
    ]);
    PayrollLine::query()->create([
        'payroll_run_id' => $run->id, 'business_id' => $this->admin->business_id,
        'personnel_id' => $this->personnel->id, 'personnel_code_snapshot' => $this->personnel->code,
        'personnel_name_snapshot' => $this->personnel->full_name, 'hourly_basic_pay' => 800,
        'ot_pay' => 0, 'commission_amount' => 200, 'guaranteed_top_up' => 0,
        'gross_compensation' => 1000, 'adjustments_total' => 0, 'cash_advance_deductions' => 100,
        'net_pay' => 900, 'status' => 'APPROVED',
    ]);

    $this->actingAs($barber)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.personnel.id', $this->personnel->id)
        ->assertJsonPath('user.personnel.has_operational_compensation', true);

    $this->actingAs($barber)->getJson('/api/v1/hr/my-payroll')
        ->assertOk()->assertJsonPath('personnel.id', $this->personnel->id)
        ->assertJsonPath('rows.0.net_pay', 900)
        ->assertJsonPath('summary.projected_running_net', 0);
});

test('my payroll is unavailable when linked personnel has no operational compensation', function () {
    $barber = foundationUser('ROLE-BAR');
    $noneRule = ReferenceValue::query()
        ->where('code', 'NONE')
        ->whereHas('group', fn ($query) => $query->where('code', 'COMPENSATION_RULE'))
        ->firstOrFail();

    $this->personnel->update([
        'linked_user_id' => $barber->id,
        'compensation_rule_reference_id' => $noneRule->id,
        'hourly_rate' => 0,
        'guaranteed_daily_pay' => 0,
    ]);

    $this->actingAs($barber)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.personnel.has_operational_compensation', false);

    $this->actingAs($barber)->getJson('/api/v1/hr/my-payroll')
        ->assertStatus(422)
        ->assertJsonPath('message', 'My Payroll is not available because this Personnel record has no operational compensation setup.');
});


test('payroll index returns active branches using the branch status column', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/hr/payroll')
        ->assertOk()
        ->assertJsonPath('branches.0.code', 'YDM-SJC')
        ->assertJsonPath('branches.0.name', 'San Jose City');
});


test('payroll period cannot include future dates', function () {
    $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->toDateString();
    $tomorrow = now(config('yodamen.business_timezone', 'Asia/Manila'))->addDay()->toDateString();

    $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => $today,
        'period_end' => $tomorrow,
        'pay_date' => $tomorrow,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Payroll period cannot include future dates.');
});

test('cash advance deduction is capped by available payroll net and carries the balance forward', function () {
    Attendance::query()->create([
        'business_id' => $this->admin->business_id,
        'personnel_id' => $this->personnel->id,
        'attendance_date' => '2026-09-18',
        'source' => 'ADMIN_ADJUSTMENT',
        'workflow_status' => 'APPROVED',
        'attendance_status' => 'PRESENT',
        'time_in_at' => '2026-09-18 09:00:00',
        'time_out_at' => '2026-09-18 14:00:00',
        'worked_minutes' => 300,
        'ot_minutes' => 0,
        'approved_by' => $this->admin->id,
        'approved_at' => '2026-09-18 14:01:00',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);

    $advance = EmployeeCashAdvance::query()->create([
        'business_id' => $this->admin->business_id,
        'code' => 'CA-CAP-001',
        'personnel_id' => $this->personnel->id,
        'issued_date' => '2026-09-01',
        'original_amount' => 3000,
        'deduction_per_payroll' => 800,
        'purpose' => 'Capacity test',
        'status' => 'ACTIVE',
        'created_by' => $this->admin->id,
    ]);
    EmployeeCashAdvanceLedger::query()->create([
        'business_id' => $this->admin->business_id,
        'cash_advance_id' => $advance->id,
        'entry_date' => '2026-09-01',
        'entry_type' => 'ISSUANCE',
        'amount' => 3000,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18',
        'period_end' => '2026-09-18',
        'pay_date' => '2026-09-19',
    ])->assertCreated();

    $availableBeforeAdvance = (float) $response->json('run.lines.0.gross_compensation') + (float) $response->json('run.lines.0.adjustments_total');
    $actualDeduction = (float) $response->json('run.lines.0.cash_advance_deductions');
    expect($actualDeduction)->toBe(min(800.0, $availableBeforeAdvance))
        ->and((float) $response->json('run.lines.0.net_pay'))->toBe(max(0.0, $availableBeforeAdvance - $actualDeduction));

    $runId = $response->json('run.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")->assertOk();

    $remaining = (float) EmployeeCashAdvanceLedger::query()->where('cash_advance_id', $advance->id)->sum('amount');
    expect($remaining)->toBe(3000.0 - $actualDeduction);
});


test('overlapping payroll periods are blocked for the same payroll scope', function () {
    createApprovedPayrollAttendance($this);
    $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated();

    $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-19', 'pay_date' => '2026-09-20',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'An active payroll run already overlaps this payroll period for the selected branch scope.');
});

test('only manager can waive one payroll cash advance deduction and the balance carries forward', function () {
    createApprovedPayrollAttendance($this);
    $manager = foundationUser('ROLE-MGR', ['username' => 'payroll.manager']);

    $advance = EmployeeCashAdvance::query()->create([
        'business_id' => $this->admin->business_id, 'code' => 'CA-WAIVE-001', 'personnel_id' => $this->personnel->id,
        'issued_date' => '2026-09-01', 'original_amount' => 3000, 'deduction_per_payroll' => 800,
        'purpose' => 'Waiver test', 'status' => 'ACTIVE', 'created_by' => $this->admin->id,
    ]);
    EmployeeCashAdvanceLedger::query()->create([
        'business_id' => $this->admin->business_id, 'cash_advance_id' => $advance->id,
        'entry_date' => '2026-09-01', 'entry_type' => 'ISSUE', 'amount' => 3000, 'created_by' => $this->admin->id,
    ]);

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-18', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-19',
    ])->assertCreated()->assertJsonPath('run.cash_advance_deductions', 800)->json('run.id');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/cash-advances/{$advance->id}/waive", ['reason' => 'Super Admin cannot perform manager-only waiver.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only a Manager can waive or restore a cash advance deduction for a payroll run.');

    $this->actingAs($manager)->postJson("/api/v1/hr/payroll/{$runId}/cash-advances/{$advance->id}/waive", ['reason' => 'Employee requested skip for this cutoff.'])
        ->assertOk()
        ->assertJsonPath('run.cash_advance_deductions', 0);

    expect(PayrollCashAdvanceWaiver::query()->where('payroll_run_id', $runId)->where('cash_advance_id', $advance->id)->where('status', 'WAIVED')->exists())->toBeTrue()
        ->and((float) EmployeeCashAdvanceLedger::query()->where('cash_advance_id', $advance->id)->sum('amount'))->toBe(3000.0);
});

test('payroll can be paid from petty cash and automatically appears in the petty cash subledger', function () {
    createApprovedPayrollAttendance($this);

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-25',
    ])->assertCreated()->json('run.id');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertOk()->assertJsonPath('run.status', 'APPROVED');

    $petty = ChartOfAccount::query()
        ->where('business_id', $this->admin->business_id)
        ->where('system_key', 'PETTY_CASH')
        ->firstOrFail();

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/mark-paid", [
        'funding_account_id' => $petty->id,
        'reference' => 'PC-PAYROLL-001',
    ])->assertOk()
        ->assertJsonPath('run.status', 'PAID')
        ->assertJsonPath('run.payment_account.id', $petty->id);

    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYROLL_RUN')
        ->where('source_id', $runId)
        ->where('source_event', 'PAY')
        ->with('lines')
        ->firstOrFail();

    expect((float) $journal->lines->where('account_id', $petty->id)->sum('credit'))->toBe(800.0);

    $this->actingAs($this->admin)->getJson('/api/v1/petty-cash?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertJsonPath('rows.0.source_label', 'Payroll')
        ->assertJsonPath('rows.0.out', 800);
});


test('payroll can be marked paid using up to five distinct funding sources that exactly equal net pay', function () {
    createApprovedPayrollAttendance($this);

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-25',
    ])->assertCreated()->json('run.id');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")
        ->assertOk()->assertJsonPath('run.status', 'APPROVED');

    $bank = ChartOfAccount::query()
        ->where('business_id', $this->admin->business_id)
        ->where('system_key', 'CASH_IN_BANK')
        ->firstOrFail();
    $petty = ChartOfAccount::query()
        ->where('business_id', $this->admin->business_id)
        ->where('system_key', 'PETTY_CASH')
        ->firstOrFail();

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/mark-paid", [
        'funding_splits' => [
            ['funding_account_id' => $bank->id, 'amount' => 500, 'reference' => 'BDO-PAY-001'],
            ['funding_account_id' => $petty->id, 'amount' => 300, 'reference' => 'PC-PAY-001'],
        ],
    ])->assertOk()
      ->assertJsonPath('run.status', 'PAID')
      ->assertJsonCount(2, 'run.payment_splits')
      ->assertJsonPath('run.payment_splits.0.amount', 500)
      ->assertJsonPath('run.payment_splits.1.amount', 300);

    expect(PayrollPaymentSplit::query()->where('payroll_run_id', $runId)->count())->toBe(2);

    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYROLL_RUN')
        ->where('source_id', $runId)
        ->where('source_event', 'PAY')
        ->with('lines')
        ->firstOrFail();

    expect((float) $journal->lines->where('account_id', $bank->id)->sum('credit'))->toBe(500.0)
        ->and((float) $journal->lines->where('account_id', $petty->id)->sum('credit'))->toBe(300.0);
});

test('payroll split funding rejects an incomplete allocation', function () {
    createApprovedPayrollAttendance($this);

    $runId = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll', [
        'period_start' => '2026-09-16', 'period_end' => '2026-09-18', 'pay_date' => '2026-09-25',
    ])->assertCreated()->json('run.id');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/approve")->assertOk();

    $bank = ChartOfAccount::query()
        ->where('business_id', $this->admin->business_id)
        ->where('system_key', 'CASH_IN_BANK')
        ->firstOrFail();

    $this->actingAs($this->admin)->postJson("/api/v1/hr/payroll/{$runId}/mark-paid", [
        'funding_splits' => [
            ['funding_account_id' => $bank->id, 'amount' => 700],
        ],
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Payroll funding total must equal the Net Pay.');

    expect(PayrollPaymentSplit::query()->where('payroll_run_id', $runId)->count())->toBe(0)
        ->and(PayrollRun::query()->findOrFail($runId)->status)->toBe('APPROVED');
});


test('open payroll adjustment reversal requires approval before changing payroll source status', function () {
    $created = $this->actingAs($this->admin)->postJson('/api/v1/hr/payroll-adjustments', [
        'personnel_id' => $this->personnel->id,
        'effective_date' => '2026-09-18',
        'adjustment_type' => 'CORRECTION',
        'amount' => 125,
        'description' => 'Test correction',
        'reason' => 'Test correction',
    ])->assertCreated();

    $adjustmentId = $created->json('adjustment.id');
    $request = $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/payroll-adjustments/'.$adjustmentId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('WRONG_EMPLOYEE'), 'reason' => 'Correction was entered against the wrong employee.'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING');

    expect(\App\Models\PayrollAdjustment::query()->findOrFail($adjustmentId)->status)->toBe('OPEN');

    $this->actingAs($this->admin)->getJson('/api/v1/hr/payroll')
        ->assertOk()
        ->assertJsonPath('adjustments.0.pending_reversal.status', 'PENDING');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hr/payroll-adjustment-reversal-requests/'.$request->json('reversal_request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED')
        ->assertJsonPath('adjustment.status', 'REVERSED');

    expect(\App\Models\PayrollAdjustment::query()->findOrFail($adjustmentId)->status)->toBe('REVERSED');
});
