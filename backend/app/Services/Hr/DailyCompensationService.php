<?php

namespace App\Services\Hr;

use App\Models\Attendance;
use App\Models\CommissionEarning;
use App\Models\DailyCompensation;
use App\Models\HrLeaveRequest;
use App\Models\Personnel;
use App\Models\PersonnelCompensationTerm;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyCompensationService
{
    public function __construct(
        private readonly BusinessClock $clock,
        private readonly WorkScheduleService $workSchedules,
    ) {
    }

    public function list(User $actor, array $filters = []): array
    {
        $today = CarbonImmutable::parse($this->clock->today(), $this->clock->timezone());
        $from = $this->date($filters['from'] ?? null, $today->startOfMonth()->toDateString());
        $to = $this->date($filters['to'] ?? null, $today->toDateString());
        $personnelId = isset($filters['personnel_id']) && $filters['personnel_id'] !== ''
            ? (int) $filters['personnel_id']
            : null;

        $query = DailyCompensation::query()
            ->where('business_id', $actor->business_id)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->with(['personnel.position', 'attendance', 'leaveRequest.leaveType']);

        if ($personnelId) {
            $query->where('personnel_id', $personnelId);
        }

        $rows = $query->orderByDesc('work_date')->orderBy('personnel_id')->get();

        return [
            'rows' => $rows->map(fn (DailyCompensation $row) => $this->serialize($row))->values(),
            'totals' => [
                'base_pay' => round((float) $rows->sum('hourly_basic_pay'), 2),
                'ot_pay' => round((float) $rows->sum('ot_pay'), 2),
                'commission' => round((float) $rows->sum('confirmed_commission'), 2),
                'guaranteed_top_up' => round((float) $rows->sum('guaranteed_top_up'), 2),
                'gross' => round((float) $rows->sum('gross_daily_projection'), 2),
            ],
            'personnel' => Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')->orderBy('first_name')
                ->get()
                ->map(fn (Personnel $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->full_name])
                ->values(),
            'filters' => ['from' => $from, 'to' => $to, 'personnel_id' => $personnelId],
        ];
    }

    public function rebuildRange(User $actor, string $from, string $to, ?int $personnelId = null): array
    {
        $fromDate = CarbonImmutable::parse($from, $this->clock->timezone())->startOfDay();
        $toDate = CarbonImmutable::parse($to, $this->clock->timezone())->startOfDay();

        if ($toDate->lessThan($fromDate)) {
            throw new DomainException('To date must be on or after From date.');
        }
        if ($fromDate->diffInDays($toDate) > 62) {
            throw new DomainException('Daily Compensation can be rebuilt for a maximum of 63 days at a time.');
        }

        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->when($personnelId, fn ($query) => $query->whereKey($personnelId))
            ->with('compensationRule')
            ->get();

        foreach ($personnel as $record) {
            for ($date = $fromDate; $date->lessThanOrEqualTo($toDate); $date = $date->addDay()) {
                $this->recalculatePersonnelDay($record, $date);
            }
        }

        return $this->list($actor, ['from' => $from, 'to' => $to, 'personnel_id' => $personnelId]);
    }

    public function recalculatePersonnelDay(Personnel $personnel, CarbonImmutable|string $date): ?DailyCompensation
    {
        $date = $date instanceof CarbonImmutable
            ? $date->setTimezone($this->clock->timezone())->startOfDay()
            : CarbonImmutable::parse($date, $this->clock->timezone())->startOfDay();
        $dateKey = $date->toDateString();

        return DB::transaction(function () use ($personnel, $date, $dateKey) {
            $existing = DailyCompensation::query()
                ->where('personnel_id', $personnel->id)
                ->whereDate('work_date', $dateKey)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status !== 'OPEN') {
                return $existing;
            }

            $personnel->loadMissing('compensationRule');
            $compensation = $this->compensationForDate($personnel, $date);
            $schedule = $this->workSchedules->resolveForDate($personnel, $date);

            $attendance = Attendance::query()
                ->where('business_id', $personnel->business_id)
                ->where('personnel_id', $personnel->id)
                ->whereDate('attendance_date', $dateKey)
                ->whereIn('workflow_status', ['APPROVED', 'ADJUSTED'])
                ->first();

            $leave = HrLeaveRequest::query()
                ->where('business_id', $personnel->business_id)
                ->where('personnel_id', $personnel->id)
                ->where('request_type', 'LEAVE')
                ->where('status', 'APPROVED')
                ->whereDate('start_date', '<=', $dateKey)
                ->whereDate('end_date', '>=', $dateKey)
                ->with('leaveType')
                ->first();

            $commission = round((float) CommissionEarning::query()
                ->where('business_id', $personnel->business_id)
                ->where('personnel_id', $personnel->id)
                ->whereDate('work_date', $dateKey)
                ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT', 'APPROVED', 'PAID'])
                ->sum('commission_amount'), 2);

            $scheduledMinutes = (int) ($schedule['expected_minutes'] ?? 0);
            $workedMinutes = (int) ($attendance?->worked_minutes ?? 0);
            $otMinutes = (int) ($attendance?->ot_minutes ?? 0);
            $regularWorkedMinutes = max(0, $workedMinutes - $otMinutes);

            $paidLeaveUnits = 0.0;
            if ($leave?->paid_leave_approved && ($schedule['is_working_day'] ?? false)) {
                $paidLeaveUnits = $leave->duration_type === 'FULL_DAY' ? 1.0 : 0.5;
            }
            $paidLeaveMinutes = (int) round($scheduledMinutes * $paidLeaveUnits);

            $hasSource = $attendance !== null
                || $leave !== null
                || $commission > 0
                || (($schedule['configured'] ?? false) && ($schedule['is_working_day'] ?? false));

            if (! $hasSource) {
                if ($existing) {
                    $existing->delete();
                }
                return null;
            }

            $hourlyRate = round((float) $compensation['hourly_rate'], 2);
            $regularCompMinutes = $scheduledMinutes > 0
                ? min($scheduledMinutes, $regularWorkedMinutes + $paidLeaveMinutes)
                : $regularWorkedMinutes + $paidLeaveMinutes;
            $hourlyBasic = round(($regularCompMinutes / 60) * $hourlyRate, 2);

            $otPay = 0.0;
            if ($compensation['ot_entitled'] && $otMinutes > 0) {
                $multiplier = max(0.001, (float) ($compensation['ot_multiplier'] ?? 1));
                $otPay = round(($otMinutes / 60) * $hourlyRate * $multiplier, 2);
            }

            $ruleCode = strtoupper((string) ($compensation['rule_code'] ?? 'NONE'));
            $guaranteedDaily = round((float) $compensation['guaranteed_daily_pay'], 2);
            $guaranteedTarget = 0.0;
            $guaranteedTopUp = 0.0;
            $baseComponent = 0.0;
            $gross = 0.0;

            if ($ruleCode === 'HOURLY_PLUS_COMMISSION') {
                $baseComponent = round($hourlyBasic + $commission, 2);
                $gross = round($baseComponent + $otPay, 2);
            } elseif ($ruleCode === 'GUARANTEED_VS_COMMISSION') {
                $coveredMinutes = $regularWorkedMinutes + $paidLeaveMinutes;
                $coverageRatio = $scheduledMinutes > 0
                    ? min(1, max(0, $coveredMinutes / $scheduledMinutes))
                    : ($coveredMinutes > 0 ? 1 : 0);
                $guaranteedTarget = round($guaranteedDaily * $coverageRatio, 2);
                $guaranteedTopUp = round(max(0, $guaranteedTarget - $commission), 2);
                $baseComponent = round(max($guaranteedTarget, $commission), 2);
                $gross = round($baseComponent + $otPay, 2);
            }

            $attributes = [
                'business_id' => $personnel->business_id,
                'attendance_id' => $attendance?->id,
                'leave_request_id' => $leave?->id,
                'compensation_rule_snapshot' => $ruleCode,
                'hourly_rate_snapshot' => $hourlyRate,
                'ot_entitled_snapshot' => (bool) $compensation['ot_entitled'],
                'ot_multiplier_snapshot' => $compensation['ot_multiplier'],
                'guaranteed_daily_pay_snapshot' => $guaranteedDaily,
                'scheduled_minutes' => $scheduledMinutes,
                'worked_minutes' => $workedMinutes,
                'ot_minutes' => $otMinutes,
                'paid_leave_units' => $paidLeaveUnits,
                'paid_leave_minutes' => $paidLeaveMinutes,
                'hourly_basic_pay' => $hourlyBasic,
                'ot_pay' => $otPay,
                'confirmed_commission' => $commission,
                'guaranteed_target' => $guaranteedTarget,
                'guaranteed_top_up' => $guaranteedTopUp,
                'base_compensation_component' => $baseComponent,
                'gross_daily_projection' => $gross,
                'status' => 'OPEN',
                'recalculated_at' => $this->clock->now(),
            ];

            if ($existing) {
                $existing->update($attributes);
                return $existing->refresh();
            }

            return DailyCompensation::query()->create([
                ...$attributes,
                'personnel_id' => $personnel->id,
                'work_date' => $dateKey,
            ]);
        });
    }

    public function serialize(DailyCompensation $row): array
    {
        $row->loadMissing(['personnel.position', 'leaveRequest.leaveType']);

        return [
            'id' => $row->id,
            'work_date' => $row->work_date?->toDateString(),
            'personnel' => $row->personnel ? [
                'id' => $row->personnel->id,
                'code' => $row->personnel->code,
                'name' => $row->personnel->full_name,
                'position' => $row->personnel->position?->display_value,
            ] : null,
            'compensation_rule' => $row->compensation_rule_snapshot,
            'scheduled_hours' => round($row->scheduled_minutes / 60, 2),
            'worked_hours' => round($row->worked_minutes / 60, 2),
            'ot_hours' => round($row->ot_minutes / 60, 2),
            'paid_leave_units' => (float) $row->paid_leave_units,
            'leave_type' => $row->leaveRequest?->leaveType?->name,
            'hourly_basic_pay' => (float) $row->hourly_basic_pay,
            'ot_pay' => (float) $row->ot_pay,
            'confirmed_commission' => (float) $row->confirmed_commission,
            'guaranteed_target' => (float) $row->guaranteed_target,
            'guaranteed_top_up' => (float) $row->guaranteed_top_up,
            'base_compensation_component' => (float) $row->base_compensation_component,
            'gross_daily_projection' => (float) $row->gross_daily_projection,
            'status' => $row->status,
            'recalculated_at' => $row->recalculated_at?->toIso8601String(),
        ];
    }

    private function compensationForDate(Personnel $personnel, CarbonImmutable $date): array
    {
        $term = PersonnelCompensationTerm::query()
            ->where('personnel_id', $personnel->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->with('compensationRule')
            ->orderByDesc('effective_from')
            ->first();

        if ($term) {
            return [
                'rule_code' => $term->compensationRule?->code ?? 'NONE',
                'hourly_rate' => (float) $term->hourly_rate,
                'ot_entitled' => (bool) $term->ot_entitled,
                'ot_multiplier' => $term->ot_multiplier !== null ? (float) $term->ot_multiplier : null,
                'guaranteed_daily_pay' => (float) $term->guaranteed_daily_pay,
            ];
        }

        return [
            'rule_code' => $personnel->compensationRule?->code ?? 'NONE',
            'hourly_rate' => (float) $personnel->hourly_rate,
            'ot_entitled' => (bool) $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier !== null ? (float) $personnel->ot_multiplier : null,
            'guaranteed_daily_pay' => (float) $personnel->guaranteed_daily_pay,
        ];
    }

    private function date(?string $value, string $fallback): string
    {
        try {
            return $value ? CarbonImmutable::parse($value, $this->clock->timezone())->toDateString() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
