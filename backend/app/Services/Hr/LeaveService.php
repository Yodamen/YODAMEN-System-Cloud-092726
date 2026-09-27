<?php

namespace App\Services\Hr;

use App\Models\HrLeaveRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Personnel;
use App\Models\PersonnelScheduleOverride;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(
        private readonly BusinessClock $clock,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly WorkScheduleService $workSchedules,
        private readonly DailyCompensationService $dailyCompensation,
    ) {
    }

    public function myData(User $actor): array
    {
        $personnel = $this->resolvePersonnel($actor);
        $year = (int) $this->clock->now()->format('Y');

        return [
            'personnel' => ['id' => $personnel->id, 'code' => $personnel->code, 'name' => $personnel->full_name, 'paid_leave_entitled' => (bool) $personnel->paid_leave_entitled],
            'leave_types' => $this->leaveTypes((int) $actor->business_id, $personnel),
            'balances' => $this->balancesFor($personnel, $year),
            'requests' => HrLeaveRequest::query()
                ->where('business_id', $actor->business_id)
                ->where('personnel_id', $personnel->id)
                ->with(['leaveType', 'reviewer'])
                ->orderByDesc('submitted_at')
                ->limit(100)
                ->get()
                ->map(fn (HrLeaveRequest $request) => $this->serialize($request))
                ->values(),
            'year' => $year,
            'work_schedule' => $this->workSchedules->get($actor, $personnel),
        ];
    }

    public function adminData(User $actor, array $filters = []): array
    {
        $status = strtoupper(trim((string) ($filters['status'] ?? 'ALL')));
        $personnelId = isset($filters['personnel_id']) && $filters['personnel_id'] !== '' ? (int) $filters['personnel_id'] : null;
        $today = CarbonImmutable::parse($this->clock->today(), $this->clock->timezone());
        $from = $this->safeDate($filters['from'] ?? null, $today->startOfYear()->toDateString());
        $to = $this->safeDate($filters['to'] ?? null, $today->endOfYear()->toDateString());

        $query = HrLeaveRequest::query()
            ->where('business_id', $actor->business_id)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->with(['personnel.position', 'leaveType', 'reviewer']);

        if ($status !== 'ALL' && $status !== '') {
            $query->where('status', $status);
        }
        if ($personnelId) {
            $query->where('personnel_id', $personnelId);
        }

        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        return [
            'rows' => $query->orderByDesc('submitted_at')->get()->map(fn (HrLeaveRequest $request) => $this->serialize($request))->values(),
            'personnel' => $personnel->map(fn (Personnel $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->full_name, 'paid_leave_entitled' => (bool) $row->paid_leave_entitled])->values(),
            'leave_types' => $this->leaveTypes((int) $actor->business_id),
            'filters' => ['status' => $status ?: 'ALL', 'personnel_id' => $personnelId, 'from' => $from, 'to' => $to],
        ];
    }

    public function adminBalances(User $actor, Personnel $personnel, int $year): array
    {
        if ((int) $personnel->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        return [
            'personnel' => ['id' => $personnel->id, 'code' => $personnel->code, 'name' => $personnel->full_name, 'paid_leave_entitled' => (bool) $personnel->paid_leave_entitled],
            'year' => $year,
            'balances' => $personnel->paid_leave_entitled
                ? $this->balancesFor($personnel, $year)->map(
                    fn (array $balance) => ['personnel_id' => $personnel->id, 'personnel_name' => $personnel->full_name, ...$balance]
                )->values()
                : collect(),
        ];
    }

    public function submit(User $actor, array $data): HrLeaveRequest
    {
        $personnel = $this->resolvePersonnel($actor);
        $requestType = strtoupper($data['request_type']);
        $duration = strtoupper($data['duration_type']);
        $start = CarbonImmutable::parse($data['start_date'], $this->clock->timezone());
        $end = CarbonImmutable::parse($data['end_date'], $this->clock->timezone());

        if ($start->isBefore(CarbonImmutable::parse($this->clock->today(), $this->clock->timezone()))) {
            throw new DomainException('New leave or day-off requests cannot be backdated.');
        }
        if ($duration !== 'FULL_DAY' && ! $start->isSameDay($end)) {
            throw new DomainException('Half-day requests must use the same Start and End date.');
        }
        if ($requestType === 'DAY_OFF' && $duration !== 'FULL_DAY') {
            throw new DomainException('Day Off requests must use Full Day duration.');
        }

        $leaveType = null;
        if ($requestType === 'LEAVE') {
            if (! $personnel->paid_leave_entitled) {
                throw new DomainException('This personnel record is not entitled to leave.');
            }
            if (empty($data['leave_type_id'])) {
                throw new DomainException('Leave Type is required for a Leave request.');
            }
            $leaveType = LeaveType::query()
                ->where('business_id', $actor->business_id)
                ->where('active', true)
                ->whereKey((int) $data['leave_type_id'])
                ->firstOrFail();
        }

        $overlap = HrLeaveRequest::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            throw new DomainException('You already have a Pending or Approved request that overlaps these dates.');
        }

        return DB::transaction(function () use ($actor, $personnel, $data, $requestType, $leaveType) {
            $now = $this->clock->now();
            $request = HrLeaveRequest::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('LEAVE_REQUEST:'.$now->format('my'), 'LVR-'.$now->format('my').'-', 6, $actor->business_id),
                'personnel_id' => $personnel->id,
                'request_type' => $requestType,
                'leave_type_id' => $leaveType?->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'duration_type' => strtoupper($data['duration_type']),
                'reason' => trim($data['reason']),
                'status' => 'PENDING',
                'submitted_by' => $actor->id,
                'submitted_at' => $now,
            ]);

            $this->audit->record(
                action: 'SUBMIT_LEAVE_REQUEST',
                module: 'my-leave',
                entityType: HrLeaveRequest::class,
                entityId: $request->id,
                after: $this->snapshot($request),
                actor: $actor,
            );

            return $request->load(['personnel', 'leaveType', 'reviewer']);
        });
    }

    public function cancel(User $actor, HrLeaveRequest $request): HrLeaveRequest
    {
        $personnel = $this->resolvePersonnel($actor);
        if ((int) $request->business_id !== (int) $actor->business_id || (int) $request->personnel_id !== (int) $personnel->id) {
            abort(404);
        }
        if (! in_array($request->status, ['PENDING', 'APPROVED'], true)) {
            throw new DomainException('Only Pending or Approved requests can be cancelled.');
        }

        return DB::transaction(function () use ($actor, $request) {
            $before = $this->snapshot($request);
            if ($request->request_type === 'DAY_OFF' && $request->status === 'APPROVED') {
                PersonnelScheduleOverride::query()
                    ->where('business_id', $actor->business_id)
                    ->where('source_type', 'LEAVE_REQUEST')
                    ->where('source_reference_id', $request->id)
                    ->delete();
            }

            $request->update([
                'status' => 'CANCELLED',
                'cancelled_by' => $actor->id,
                'cancelled_at' => $this->clock->now(),
            ]);
            $this->recalculateRequestDates($request);

            $this->audit->record(
                action: 'CANCEL_LEAVE_REQUEST',
                module: 'my-leave',
                entityType: HrLeaveRequest::class,
                entityId: $request->id,
                before: $before,
                after: $this->snapshot($request),
                actor: $actor,
            );

            return $request->refresh()->load(['personnel', 'leaveType', 'reviewer', 'reviewReasonReference']);
        });
    }

    public function approve(User $actor, HrLeaveRequest $request, ?string $notes = null): HrLeaveRequest
    {
        $this->assertOwned($actor, $request);
        if ($request->status !== 'PENDING') {
            throw new DomainException('Only Pending requests can be approved.');
        }

        return DB::transaction(function () use ($actor, $request, $notes) {
            $request = HrLeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($actor, $request);
            if ($request->status !== 'PENDING') {
                throw new DomainException('This request has already been reviewed.');
            }

            $personnel = Personnel::query()->whereKey($request->personnel_id)->firstOrFail();
            $units = $this->approvedUnits($personnel, $request);

            if ($request->request_type === 'LEAVE') {
                if ($units <= 0) {
                    throw new DomainException('The requested dates do not contain any scheduled workday.');
                }
                $leaveType = LeaveType::query()->whereKey($request->leave_type_id)->firstOrFail();
                $effectiveConsumesBalance = (bool) $leaveType->consumes_balance && (bool) $personnel->paid_leave_entitled;
                if ($effectiveConsumesBalance) {
                    $year = (int) $request->start_date->format('Y');
                    if ((int) $request->end_date->format('Y') !== $year) {
                        throw new DomainException('Balance-consuming leave requests cannot cross calendar years. File separate requests per year.');
                    }
                    $available = $this->availableBalance($personnel, $leaveType, $year, $request->id);
                    if ($units > $available + 0.0001) {
                        throw new DomainException(sprintf('Insufficient %s balance. Available: %.2f day(s).', $leaveType->name, $available));
                    }
                }
            } else {
                if ($units <= 0) {
                    throw new DomainException('Day Off can only be approved for dates currently scheduled as workdays.');
                }
                $this->createDayOffOverrides($actor, $personnel, $request);
            }

            $before = $this->snapshot($request);
            $request->update([
                'status' => 'APPROVED',
                'approved_units' => $units,
                'paid_leave_approved' => $request->request_type === 'LEAVE'
                    && (bool) ($request->leaveType?->is_paid)
                    && (bool) $personnel->paid_leave_entitled,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $this->clock->now(),
                'review_notes' => trim((string) $notes) ?: null,
            ]);
            $this->recalculateRequestDates($request);

            $this->audit->record(
                action: 'APPROVE_LEAVE_REQUEST',
                module: 'leave',
                entityType: HrLeaveRequest::class,
                entityId: $request->id,
                before: $before,
                after: $this->snapshot($request),
                actor: $actor,
            );

            return $request->refresh()->load(['personnel', 'leaveType', 'reviewer', 'reviewReasonReference']);
        });
    }

    public function reject(User $actor, HrLeaveRequest $request, ?string $notes = null, ?int $reviewReasonReferenceId = null): HrLeaveRequest
    {
        $this->assertOwned($actor, $request);
        if ($request->status !== 'PENDING') {
            throw new DomainException('Only Pending requests can be rejected.');
        }

        $before = $this->snapshot($request);
        $request->update([
            'status' => 'REJECTED',
            'reviewed_by' => $actor->id,
            'reviewed_at' => $this->clock->now(),
            'review_notes' => trim((string) $notes) ?: null,
            'review_reason_reference_id' => $reviewReasonReferenceId,
        ]);

        $this->audit->record(
            action: 'REJECT_LEAVE_REQUEST',
            module: 'leave',
            entityType: HrLeaveRequest::class,
            entityId: $request->id,
            before: $before,
            after: $this->snapshot($request),
            actor: $actor,
        );

        return $request->refresh()->load(['personnel', 'leaveType', 'reviewer', 'reviewReasonReference']);
    }

    public function saveBalance(User $actor, Personnel $personnel, LeaveType $leaveType, int $year, array $data): array
    {
        if ((int) $personnel->business_id !== (int) $actor->business_id || (int) $leaveType->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        if ($leaveType->is_paid && ! $personnel->paid_leave_entitled) {
            throw new DomainException('This personnel record is not entitled to paid leave. Enable Paid Leave Entitled under Personnel first.');
        }

        $balance = LeaveBalance::query()->updateOrCreate(
            ['personnel_id' => $personnel->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
            [
                'business_id' => $actor->business_id,
                'entitled_days' => round((float) $data['entitled_days'], 2),
                'adjustment_days' => round((float) $data['adjustment_days'], 2),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'updated_by' => $actor->id,
            ],
        );

        $this->audit->record(
            action: 'UPDATE_LEAVE_BALANCE',
            module: 'leave',
            entityType: LeaveBalance::class,
            entityId: $balance->id,
            after: $balance->toArray(),
            actor: $actor,
        );

        return $this->balancePayload($personnel, $leaveType, $year);
    }

    public function approvedForDate(Personnel $personnel, CarbonImmutable|string $date): ?HrLeaveRequest
    {
        $date = $date instanceof CarbonImmutable ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();

        return HrLeaveRequest::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('request_type', 'LEAVE')
            ->where('status', 'APPROVED')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->with('leaveType')
            ->orderByDesc('id')
            ->first();
    }

    public function serialize(HrLeaveRequest $request): array
    {
        $request->loadMissing(['personnel.position', 'leaveType', 'reviewer', 'reviewReasonReference']);

        return [
            'id' => $request->id,
            'code' => $request->code,
            'personnel' => $request->personnel ? [
                'id' => $request->personnel->id,
                'code' => $request->personnel->code,
                'name' => $request->personnel->full_name,
                'position' => $request->personnel->position?->display_value,
            ] : null,
            'request_type' => $request->request_type,
            'leave_type' => $request->leaveType ? [
                'id' => $request->leaveType->id,
                'code' => $request->leaveType->code,
                'name' => $request->leaveType->name,
                'is_paid' => $request->leaveType->is_paid,
                'consumes_balance' => $request->leaveType->consumes_balance,
            ] : null,
            'start_date' => $request->start_date?->toDateString(),
            'end_date' => $request->end_date?->toDateString(),
            'duration_type' => $request->duration_type,
            'approved_units' => (float) $request->approved_units,
            'paid_leave_approved' => (bool) $request->paid_leave_approved,
            'reason' => $request->reason,
            'status' => $request->status,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'reviewed_by' => $request->reviewer?->display_name,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'review_notes' => $request->review_notes,
            'review_reason_category' => $request->reviewReasonReference ? [
                'id' => (int) $request->reviewReasonReference->id,
                'code' => $request->reviewReasonReference->code,
                'name' => $request->reviewReasonReference->display_value,
            ] : null,
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
        ];
    }

    private function leaveTypes(int $businessId, ?Personnel $personnel = null): Collection
    {
        return LeaveType::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($personnel) {
                $paidEntitled = $personnel ? (bool) $personnel->paid_leave_entitled : true;
                return [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'is_paid' => $type->is_paid,
                    'consumes_balance' => $type->consumes_balance,
                    'effective_is_paid' => (bool) $type->is_paid && $paidEntitled,
                    'effective_consumes_balance' => (bool) $type->consumes_balance && $paidEntitled,
                    'paid_leave_entitled' => $paidEntitled,
                ];
            })
            ->values();
    }

    private function balancesFor(Personnel $personnel, int $year): Collection
    {
        return LeaveType::query()
            ->where('business_id', $personnel->business_id)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => $this->balancePayload($personnel, $type, $year));
    }

    private function balancePayload(Personnel $personnel, LeaveType $type, int $year): array
    {
        $balance = LeaveBalance::query()
            ->where('personnel_id', $personnel->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        $entitled = (float) ($balance?->entitled_days ?? 0);
        $adjustment = (float) ($balance?->adjustment_days ?? 0);
        $usedQuery = HrLeaveRequest::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('leave_type_id', $type->id)
            ->where('status', 'APPROVED')
            ->whereYear('start_date', $year);
        if ($type->is_paid || $type->consumes_balance) {
            $usedQuery->where('paid_leave_approved', true);
        }
        $used = (float) $usedQuery->sum('approved_units');

        return [
            'leave_type_id' => $type->id,
            'leave_type_code' => $type->code,
            'leave_type_name' => $type->name,
            'is_paid' => $type->is_paid,
            'consumes_balance' => $type->consumes_balance,
            'paid_leave_entitled' => (bool) $personnel->paid_leave_entitled,
            'effective_is_paid' => (bool) $type->is_paid && (bool) $personnel->paid_leave_entitled,
            'effective_consumes_balance' => (bool) $type->consumes_balance && (bool) $personnel->paid_leave_entitled,
            'year' => $year,
            'entitled_days' => round($entitled, 2),
            'adjustment_days' => round($adjustment, 2),
            'used_days' => round($used, 2),
            'available_days' => (bool) $personnel->paid_leave_entitled
                ? round($entitled + $adjustment - $used, 2)
                : 0.0,
            'notes' => $balance?->notes,
        ];
    }

    private function availableBalance(Personnel $personnel, LeaveType $type, int $year, ?int $excludeRequestId = null): float
    {
        $balance = LeaveBalance::query()
            ->where('personnel_id', $personnel->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        if (! $personnel->paid_leave_entitled) {
            return 0.0;
        }

        $query = HrLeaveRequest::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('leave_type_id', $type->id)
            ->where('status', 'APPROVED')
            ->where('paid_leave_approved', true)
            ->whereYear('start_date', $year);

        if ($excludeRequestId) {
            $query->whereKeyNot($excludeRequestId);
        }

        return round(
            (float) ($balance?->entitled_days ?? 0)
            + (float) ($balance?->adjustment_days ?? 0)
            - (float) $query->sum('approved_units'),
            2
        );
    }

    private function approvedUnits(Personnel $personnel, HrLeaveRequest $request): float
    {
        $from = CarbonImmutable::parse($request->start_date->toDateString(), $this->clock->timezone());
        $to = CarbonImmutable::parse($request->end_date->toDateString(), $this->clock->timezone());
        $schedule = $this->workSchedules->resolveRange($personnel, $from, $to);

        $units = 0.0;
        foreach ($schedule as $row) {
            if (! ($row['configured'] ?? false) || ! ($row['is_working_day'] ?? false)) {
                continue;
            }
            $units += $request->duration_type === 'FULL_DAY' ? 1.0 : 0.5;
        }

        return round($units, 2);
    }

    private function createDayOffOverrides(User $actor, Personnel $personnel, HrLeaveRequest $request): void
    {
        $from = CarbonImmutable::parse($request->start_date->toDateString(), $this->clock->timezone());
        $to = CarbonImmutable::parse($request->end_date->toDateString(), $this->clock->timezone());
        $schedule = $this->workSchedules->resolveRange($personnel, $from, $to);

        foreach ($schedule as $date => $row) {
            if (! ($row['configured'] ?? false) || ! ($row['is_working_day'] ?? false)) {
                continue;
            }

            $existing = PersonnelScheduleOverride::query()
                ->where('personnel_id', $personnel->id)
                ->whereDate('schedule_date', $date)
                ->first();

            if ($existing && $existing->source_type && $existing->source_type !== 'LEAVE_REQUEST') {
                throw new DomainException('A manual schedule override already exists on '.$date.'. Resolve it before approving this Day Off request.');
            }

            PersonnelScheduleOverride::query()->updateOrCreate(
                ['personnel_id' => $personnel->id, 'schedule_date' => $date],
                [
                    'business_id' => $actor->business_id,
                    'schedule_type' => 'DAY_OFF',
                    'expected_minutes' => 0,
                    'start_time' => null,
                    'end_time' => null,
                    'notes' => 'Approved Day Off · '.$request->code,
                    'source_type' => 'LEAVE_REQUEST',
                    'source_reference_id' => $request->id,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );
        }
    }

    private function recalculateRequestDates(HrLeaveRequest $request): void
    {
        $personnel = Personnel::query()->with('compensationRule')->find($request->personnel_id);
        if (! $personnel) {
            return;
        }

        $from = CarbonImmutable::parse($request->start_date->toDateString(), $this->clock->timezone());
        $to = CarbonImmutable::parse($request->end_date->toDateString(), $this->clock->timezone());
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $this->dailyCompensation->recalculatePersonnelDay($personnel, $date);
        }
    }

    private function resolvePersonnel(User $actor): Personnel
    {
        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('linked_user_id', $actor->id)
            ->first();

        if (! $personnel) {
            throw new DomainException('Your user account is not linked to a Personnel record.');
        }
        if ($personnel->status !== 'ACTIVE') {
            throw new DomainException('Your Personnel record is inactive.');
        }

        return $personnel;
    }

    private function assertOwned(User $actor, HrLeaveRequest $request): void
    {
        if ((int) $request->business_id !== (int) $actor->business_id) {
            abort(404);
        }
    }

    private function snapshot(HrLeaveRequest $request): array
    {
        return [
            'id' => $request->id,
            'code' => $request->code,
            'personnel_id' => $request->personnel_id,
            'request_type' => $request->request_type,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date?->toDateString(),
            'end_date' => $request->end_date?->toDateString(),
            'duration_type' => $request->duration_type,
            'approved_units' => (float) $request->approved_units,
            'paid_leave_approved' => (bool) $request->paid_leave_approved,
            'status' => $request->status,
        ];
    }
    private function safeDate(?string $value, string $fallback): string
    {
        try {
            return $value ? CarbonImmutable::parse($value, $this->clock->timezone())->toDateString() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

}
