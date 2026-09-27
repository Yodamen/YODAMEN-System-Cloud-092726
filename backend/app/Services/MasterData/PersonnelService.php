<?php

namespace App\Services\MasterData;

use App\Models\Branch;
use App\Models\CommissionEarning;
use App\Models\DailyCompensation;
use App\Models\Personnel;
use App\Models\PersonnelCompensationTerm;
use App\Models\PayrollAdjustment;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PersonnelService
{
    public function __construct(
        private readonly ReferenceDataService $references,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
    ) {
    }

    public function save(User $actor, array $data, ?Personnel $personnel = null): Personnel
    {
        if ($personnel && $personnel->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $personnel) {
            $position = $this->references->valueByCode($actor->business_id, 'EMPLOYEE_POSITION', $data['position_code']);
            $personnelType = $this->references->valueByCode($actor->business_id, 'PERSONNEL_TYPE', $data['personnel_type_code']);
            $compRule = $this->references->valueByCode($actor->business_id, 'COMPENSATION_RULE', $data['compensation_rule_code']);

            if ($position->code === 'MANAGING_PARTNER') {
                $personnelType = $this->references->valueByCode($actor->business_id, 'PERSONNEL_TYPE', 'PARTNER');
            }

            $employmentType = null;
            if ($personnelType->code === 'EMPLOYEE') {
                $employmentType = $this->references->valueByCode(
                    $actor->business_id,
                    'EMPLOYMENT_TYPE',
                    ($data['employment_type_code'] ?? null) ?: 'REGULAR'
                );
            }

            $this->validateRules($actor, $data, $position->code, $personnelType->code, $compRule->code, $personnel?->id);

            $before = $personnel ? $this->snapshot($personnel->loadMissing(['position', 'personnelType', 'employmentType', 'compensationRule'])) : null;
            $compensationChanged = $this->compensationChanged($personnel, $data, $compRule->id, $compRule->code);
            $compensationEffectiveFrom = $personnel?->compensation_effective_from?->toDateString();
            if (! $personnel || $compensationChanged) {
                $compensationEffectiveFrom = $this->validatedCompensationEffectiveFrom($actor, $personnel, $data);
            }

            $isEmployee = $personnelType->code === 'EMPLOYEE';
            $sssEnabled = $isEmployee && (array_key_exists('sss_enabled', $data) ? (bool) $data['sss_enabled'] : (bool) ($personnel?->sss_enabled ?? false));
            $philhealthEnabled = $isEmployee && (array_key_exists('philhealth_enabled', $data) ? (bool) $data['philhealth_enabled'] : (bool) ($personnel?->philhealth_enabled ?? false));
            $pagibigEnabled = $isEmployee && (array_key_exists('pagibig_enabled', $data) ? (bool) $data['pagibig_enabled'] : (bool) ($personnel?->pagibig_enabled ?? false));

            $attributes = [
                'business_id' => $actor->business_id,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'mobile_number' => trim((string) ($data['mobile_number'] ?? '')) ?: null,
                'email' => strtolower(trim((string) ($data['email'] ?? ''))) ?: null,
                'position_reference_id' => $position->id,
                'branch_id' => $data['branch_id'] ?? null,
                'employment_type_reference_id' => $employmentType?->id,
                'personnel_type_reference_id' => $personnelType->id,
                'profit_sharing_eligible' => (bool) $data['profit_sharing_eligible'],
                'profit_sharing_percentage' => (bool) $data['profit_sharing_eligible']
                    ? round((float) ($data['profit_sharing_percentage'] ?? 0), 4)
                    : 0,
                'profit_sharing_effective_from' => (bool) $data['profit_sharing_eligible']
                    ? ($data['profit_sharing_effective_from'] ?? null)
                    : null,
                'profit_sharing_effective_until' => (bool) $data['profit_sharing_eligible']
                    ? ($data['profit_sharing_effective_until'] ?? null)
                    : null,
                'hourly_rate' => $compRule->code === 'NONE' ? 0 : round((float) $data['hourly_rate'], 2),
                'ot_entitled' => $compRule->code === 'NONE' ? false : (bool) $data['ot_entitled'],
                'ot_multiplier' => $compRule->code === 'NONE' || ! (bool) $data['ot_entitled']
                    ? null
                    : round((float) $data['ot_multiplier'], 3),
                'compensation_rule_reference_id' => $compRule->id,
                'guaranteed_daily_pay' => $compRule->code === 'GUARANTEED_VS_COMMISSION'
                    ? round((float) $data['guaranteed_daily_pay'], 2)
                    : 0,
                'compensation_effective_from' => $compensationEffectiveFrom,
                'can_perform_services' => (bool) $data['can_perform_services'],
                'commission_eligible' => (bool) $data['commission_eligible'],
                'paid_leave_entitled' => array_key_exists('paid_leave_entitled', $data)
                    ? (bool) $data['paid_leave_entitled']
                    : ($personnel?->paid_leave_entitled ?? true),
                'sss_enabled' => $sssEnabled,
                'sss_number' => $sssEnabled ? (array_key_exists('sss_number', $data) ? (trim((string) $data['sss_number']) ?: null) : $personnel?->sss_number) : null,
                'philhealth_enabled' => $philhealthEnabled,
                'philhealth_number' => $philhealthEnabled ? (array_key_exists('philhealth_number', $data) ? (trim((string) $data['philhealth_number']) ?: null) : $personnel?->philhealth_number) : null,
                'pagibig_enabled' => $pagibigEnabled,
                'pagibig_number' => $pagibigEnabled ? (array_key_exists('pagibig_number', $data) ? (trim((string) $data['pagibig_number']) ?: null) : $personnel?->pagibig_number) : null,
                'hire_date' => $data['hire_date'] ?? null,
                'linked_user_id' => $data['linked_user_id'] ?? null,
                'status' => strtoupper($data['status']),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'updated_by' => $actor->id,
            ];

            try {
                if ($personnel) {
                    $personnel->update($attributes);
                } else {
                    $personnel = Personnel::query()->create(array_merge($attributes, [
                        'code' => $this->codes->next('personnel', 'EMP-', 6, $actor->business_id),
                        'created_by' => $actor->id,
                    ]));
                }
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('The selected user account is already linked to another personnel record.');
                }

                throw $exception;
            }

            if (! $before || $compensationChanged) {
                $this->syncCompensationTerm($actor, $personnel, $compRule->id, $compensationEffectiveFrom);
            }

            $personnel->refresh()->load(['position', 'branch', 'employmentType', 'personnelType', 'compensationRule', 'linkedUser']);

            $this->audit->record(
                action: $before ? 'EDIT_EMPLOYEE' : 'CREATE_EMPLOYEE',
                module: 'employees',
                entityType: Personnel::class,
                entityId: $personnel->id,
                before: $before,
                after: $this->snapshot($personnel),
                actor: $actor,
            );

            return $personnel;
        });
    }

    public function serialize(Personnel $personnel): array
    {
        return [
            'id' => $personnel->id,
            'code' => $personnel->code,
            'first_name' => $personnel->first_name,
            'last_name' => $personnel->last_name,
            'full_name' => $personnel->full_name,
            'mobile_number' => $personnel->mobile_number,
            'email' => $personnel->email,
            'position' => $this->refPayload($personnel->position),
            'branch' => $personnel->branch ? [
                'id' => $personnel->branch->id,
                'code' => $personnel->branch->code,
                'name' => $personnel->branch->name,
            ] : null,
            'employment_type' => $this->refPayload($personnel->employmentType),
            'personnel_type' => $this->refPayload($personnel->personnelType),
            'profit_sharing_eligible' => $personnel->profit_sharing_eligible,
            'profit_sharing_percentage' => (float) $personnel->profit_sharing_percentage,
            'profit_sharing_effective_from' => $personnel->profit_sharing_effective_from?->toDateString(),
            'profit_sharing_effective_until' => $personnel->profit_sharing_effective_until?->toDateString(),
            'hourly_rate' => (float) $personnel->hourly_rate,
            'ot_entitled' => $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier !== null ? (float) $personnel->ot_multiplier : null,
            'compensation_rule' => $this->refPayload($personnel->compensationRule),
            'guaranteed_daily_pay' => (float) $personnel->guaranteed_daily_pay,
            'compensation_effective_from' => $personnel->compensation_effective_from?->toDateString(),
            'compensation_effective_date_control' => $this->compensationEffectiveDateControl($personnel),
            'can_perform_services' => $personnel->can_perform_services,
            'commission_eligible' => $personnel->commission_eligible,
            'paid_leave_entitled' => (bool) $personnel->paid_leave_entitled,
            'sss_enabled' => (bool) $personnel->sss_enabled,
            'sss_number' => $personnel->sss_number,
            'philhealth_enabled' => (bool) $personnel->philhealth_enabled,
            'philhealth_number' => $personnel->philhealth_number,
            'pagibig_enabled' => (bool) $personnel->pagibig_enabled,
            'pagibig_number' => $personnel->pagibig_number,
            'hire_date' => $personnel->hire_date?->toDateString(),
            'linked_user' => $personnel->linkedUser ? [
                'id' => $personnel->linkedUser->id,
                'code' => $personnel->linkedUser->code,
                'username' => $personnel->linkedUser->username,
                'display_name' => $personnel->linkedUser->display_name,
            ] : null,
            'status' => $personnel->status,
            'notes' => $personnel->notes,
        ];
    }

    private function validateRules(
        User $actor,
        array $data,
        string $positionCode,
        string $personnelTypeCode,
        string $compRuleCode,
        ?int $personnelId,
    ): void {
        if ($positionCode === 'MANAGING_PARTNER' && $personnelTypeCode !== 'PARTNER') {
            throw new DomainException('Managing Partner must use Personnel Type: Partner.');
        }

        if ($personnelTypeCode !== 'PARTNER' && (bool) $data['profit_sharing_eligible']) {
            throw new DomainException('Profit Sharing can only be enabled for Personnel Type: Partner.');
        }

        if ((bool) $data['profit_sharing_eligible']) {
            $percentage = (float) ($data['profit_sharing_percentage'] ?? 0);

            if ($percentage <= 0 || $percentage > 100 || empty($data['profit_sharing_effective_from'])) {
                throw new DomainException('Profit Sharing Percentage and Effective From are required for an eligible Partner.');
            }
        }

        if ($compRuleCode === 'GUARANTEED_VS_COMMISSION' && (float) $data['guaranteed_daily_pay'] <= 0) {
            throw new DomainException('Guaranteed Daily Pay is required for Guaranteed vs Commission personnel.');
        }

        if ($compRuleCode !== 'NONE' && (bool) $data['ot_entitled']) {
            $multiplier = $data['ot_multiplier'] ?? null;

            if ($multiplier === null || (float) $multiplier <= 0) {
                throw new DomainException('OT Multiplier is required and must be greater than 0 when OT Entitled is enabled.');
            }
        }

        if (! empty($data['branch_id'])) {
            $validBranch = Branch::query()
                ->where('id', $data['branch_id'])
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->exists();

            if (! $validBranch) {
                throw new DomainException('Selected branch is invalid or inactive.');
            }
        }

        if (! empty($data['linked_user_id'])) {
            $validUser = User::query()
                ->where('id', $data['linked_user_id'])
                ->where('business_id', $actor->business_id)
                ->exists();

            if (! $validUser) {
                throw new DomainException('Selected linked user is invalid.');
            }

            $query = Personnel::query()->where('linked_user_id', $data['linked_user_id']);
            if ($personnelId) {
                $query->whereKeyNot($personnelId);
            }

            if ($query->exists()) {
                throw new DomainException('That user account is already linked to another personnel record.');
            }
        }
    }

    private function snapshot(Personnel $personnel): array
    {
        return [
            'id' => $personnel->id,
            'code' => $personnel->code,
            'name' => $personnel->full_name,
            'position_code' => $personnel->position?->code,
            'personnel_type_code' => $personnel->personnelType?->code,
            'employment_type_code' => $personnel->employmentType?->code,
            'compensation_rule_code' => $personnel->compensationRule?->code,
            'hourly_rate' => (string) $personnel->hourly_rate,
            'ot_entitled' => $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier !== null ? (string) $personnel->ot_multiplier : null,
            'guaranteed_daily_pay' => (string) $personnel->guaranteed_daily_pay,
            'compensation_effective_from' => $personnel->compensation_effective_from?->toDateString(),
            'paid_leave_entitled' => (bool) $personnel->paid_leave_entitled,
            'sss_enabled' => (bool) $personnel->sss_enabled,
            'philhealth_enabled' => (bool) $personnel->philhealth_enabled,
            'pagibig_enabled' => (bool) $personnel->pagibig_enabled,
            'profit_sharing_eligible' => $personnel->profit_sharing_eligible,
            'profit_sharing_percentage' => (string) $personnel->profit_sharing_percentage,
            'status' => $personnel->status,
        ];
    }


    private function compensationChanged(?Personnel $personnel, array $data, int $ruleId, string $ruleCode): bool
    {
        if (! $personnel) {
            return true;
        }

        $hourlyRate = $ruleCode === 'NONE' ? 0.0 : round((float) ($data['hourly_rate'] ?? 0), 2);
        $otEntitled = $ruleCode === 'NONE' ? false : (bool) ($data['ot_entitled'] ?? false);
        $otMultiplier = $ruleCode === 'NONE' || ! $otEntitled ? null : round((float) ($data['ot_multiplier'] ?? 0), 3);
        $guaranteedDaily = $ruleCode === 'GUARANTEED_VS_COMMISSION' ? round((float) ($data['guaranteed_daily_pay'] ?? 0), 2) : 0.0;

        return (int) $personnel->compensation_rule_reference_id !== $ruleId
            || abs((float) $personnel->hourly_rate - $hourlyRate) > 0.0001
            || (bool) $personnel->ot_entitled !== $otEntitled
            || (($personnel->ot_multiplier === null ? null : round((float) $personnel->ot_multiplier, 3)) !== $otMultiplier)
            || abs((float) $personnel->guaranteed_daily_pay - $guaranteedDaily) > 0.0001;
    }

    private function validatedCompensationEffectiveFrom(User $actor, ?Personnel $personnel, array $data): string
    {
        $effectiveFrom = (string) ($data['compensation_effective_from'] ?? '');
        if ($effectiveFrom === '') {
            if ($personnel) {
                $effectiveFrom = $personnel->compensation_effective_from?->toDateString()
                    ?: $personnel->hire_date?->toDateString()
                    ?: $this->clock->today();
            } else {
                $effectiveFrom = (string) (($data['hire_date'] ?? null) ?: $this->clock->today());
            }
        }

        if ($personnel) {
            $control = $this->compensationEffectiveDateControl($personnel);
            $earliestEffectiveDate = $control['earliest_effective_date'];

            if ($earliestEffectiveDate && $effectiveFrom < $earliestEffectiveDate) {
                throw new DomainException(
                    'Compensation activity already exists through '.$control['blocked_through'].'. '
                    .'New compensation terms must take effect on or after '.$earliestEffectiveDate.'.'
                );
            }
        }

        return $effectiveFrom;
    }

    public function compensationEffectiveDateControl(Personnel $personnel): array
    {
        $lockedPayrollEnd = PayrollRun::query()
            ->where('payroll_runs.business_id', $personnel->business_id)
            ->whereIn('payroll_runs.status', ['APPROVED', 'PAID'])
            ->whereHas('lines', fn ($query) => $query->where('personnel_id', $personnel->id))
            ->max('period_end');

        $pendingCommissionDate = CommissionEarning::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT'])
            ->max('work_date');

        $openDailyCompensationDate = DailyCompensation::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT'])
            ->where(function ($query) {
                $query->whereNotNull('attendance_id')
                    ->orWhereNotNull('leave_request_id')
                    ->orWhere('confirmed_commission', '!=', 0)
                    ->orWhere('gross_daily_projection', '!=', 0);
            })
            ->max('work_date');

        $openAdjustmentDate = PayrollAdjustment::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT'])
            ->max('effective_date');

        $sources = array_filter([
            'locked_payroll' => $lockedPayrollEnd,
            'pending_commission' => $pendingCommissionDate,
            'open_daily_compensation' => $openDailyCompensationDate,
            'open_payroll_adjustment' => $openAdjustmentDate,
        ]);

        $blockedThrough = null;
        foreach ($sources as $date) {
            if ($blockedThrough === null || $date > $blockedThrough) {
                $blockedThrough = $date;
            }
        }

        $earliestEffectiveDate = $blockedThrough
            ? CarbonImmutable::parse($blockedThrough, 'Asia/Manila')->addDay()->toDateString()
            : null;

        $blockingSources = [];
        foreach ($sources as $source => $date) {
            if ($date === $blockedThrough) {
                $blockingSources[] = $source;
            }
        }

        return [
            'blocked_through' => $blockedThrough,
            'earliest_effective_date' => $earliestEffectiveDate,
            'blocking_sources' => $blockingSources,
            'latest_locked_payroll_end' => $lockedPayrollEnd,
            'latest_pending_commission_date' => $pendingCommissionDate,
            'latest_open_daily_compensation_date' => $openDailyCompensationDate,
            'latest_open_payroll_adjustment_date' => $openAdjustmentDate,
        ];
    }

    private function syncCompensationTerm(User $actor, Personnel $personnel, int $ruleId, string $effectiveFrom): void
    {
        $previous = PersonnelCompensationTerm::query()
            ->where('personnel_id', $personnel->id)
            ->whereDate('effective_from', '<', $effectiveFrom)
            ->orderByDesc('effective_from')
            ->first();

        if ($previous && ($previous->effective_until === null || $previous->effective_until->toDateString() >= $effectiveFrom)) {
            $previous->update([
                'effective_until' => CarbonImmutable::parse($effectiveFrom, 'Asia/Manila')->subDay()->toDateString(),
            ]);
        }

        $next = PersonnelCompensationTerm::query()
            ->where('personnel_id', $personnel->id)
            ->whereDate('effective_from', '>', $effectiveFrom)
            ->orderBy('effective_from')
            ->first();

        $effectiveUntil = $next
            ? $next->effective_from->subDay()->toDateString()
            : null;

        // Use whereDate rather than an exact equality match. SQLite stores Eloquent date casts
        // as midnight timestamps in tests while MySQL DATE columns store YYYY-MM-DD. The unique
        // key is date-based in both environments, so an exact updateOrCreate lookup can miss the
        // existing row in SQLite and then attempt a duplicate insert.
        $term = PersonnelCompensationTerm::query()
            ->where('personnel_id', $personnel->id)
            ->whereDate('effective_from', $effectiveFrom)
            ->first();

        $attributes = [
            'business_id' => $actor->business_id,
            'effective_until' => $effectiveUntil,
            'compensation_rule_reference_id' => $ruleId,
            'hourly_rate' => $personnel->hourly_rate,
            'ot_entitled' => $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier,
            'guaranteed_daily_pay' => $personnel->guaranteed_daily_pay,
        ];

        if ($term) {
            $term->update($attributes);
            return;
        }

        PersonnelCompensationTerm::query()->create(array_merge($attributes, [
            'personnel_id' => $personnel->id,
            'effective_from' => $effectiveFrom,
            'created_by' => $actor->id,
        ]));
    }

    private function refPayload($reference): ?array
    {
        return $reference ? [
            'id' => $reference->id,
            'code' => $reference->code,
            'display_value' => $reference->display_value,
        ] : null;
    }
}
