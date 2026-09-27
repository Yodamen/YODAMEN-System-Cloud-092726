<?php

namespace App\Services\Hr;

use App\Models\Attendance;
use App\Models\AttendanceProof;
use App\Models\HrLeaveRequest;
use App\Models\Personnel;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendanceService
{
    private const WORKFLOW_WORKING = 'WORKING';
    private const WORKFLOW_COMPLETED = 'COMPLETED';
    private const WORKFLOW_APPROVED = 'APPROVED';
    private const WORKFLOW_ADJUSTED = 'ADJUSTED';

    public function __construct(
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly WorkScheduleService $workSchedules,
        private readonly DailyCompensationService $dailyCompensation,
    ) {
    }

    public function adminDashboard(User $actor, array $filters = []): array
    {
        $today = $this->clock->today();
        $from = $this->normalizeDate($filters['from'] ?? null, CarbonImmutable::parse($today)->startOfMonth()->toDateString());
        $to = $this->normalizeDate($filters['to'] ?? null, $today);
        $personnelId = isset($filters['personnel_id']) && $filters['personnel_id'] !== ''
            ? (int) $filters['personnel_id']
            : null;
        $status = strtoupper(trim((string) ($filters['status'] ?? 'ALL')));

        $query = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->with(['personnel.position', 'branch', 'proofs', 'approver', 'adjuster'])
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);

        if ($personnelId) {
            $query->where('personnel_id', $personnelId);
        }

        if ($status === 'MISSING_TIME_OUT') {
            $query->where('workflow_status', self::WORKFLOW_WORKING)
                ->whereDate('attendance_date', '<', $today);
        } elseif ($status !== '' && $status !== 'ALL') {
            $query->where('workflow_status', $status);
        }

        $rows = $query
            ->orderByDesc('attendance_date')
            ->orderByDesc('time_in_at')
            ->get()
            ->map(fn (Attendance $attendance) => $this->serialize($attendance))
            ->values();

        $businessAttendances = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->get(['attendance_date', 'workflow_status', 'time_in_at']);

        $summary = [
            'timed_in_today' => $businessAttendances->filter(
                fn (Attendance $attendance) => $attendance->attendance_date?->toDateString() === $today && $attendance->time_in_at !== null
            )->count(),
            'currently_working' => $businessAttendances->where('workflow_status', self::WORKFLOW_WORKING)->count(),
            'awaiting_approval' => $businessAttendances->where('workflow_status', self::WORKFLOW_COMPLETED)->count(),
            'missing_time_out' => $businessAttendances->filter(
                fn (Attendance $attendance) => $attendance->workflow_status === self::WORKFLOW_WORKING
                    && $attendance->attendance_date?->toDateString() < $today
            )->count(),
        ];

        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Personnel $record) => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->full_name,
            ])
            ->values();

        return [
            'rows' => $rows,
            'summary' => $summary,
            'personnel' => $personnel,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'personnel_id' => $personnelId,
                'status' => $status ?: 'ALL',
            ],
            'today' => $today,
            'refreshed_at' => $this->clock->now()->toIso8601String(),
        ];
    }

    public function myAttendance(User $actor, array $filters = []): array
    {
        $personnel = $this->resolvePersonnel($actor, false);
        $today = $this->clock->today();
        $from = $this->normalizeDate($filters['from'] ?? null, CarbonImmutable::parse($today)->subDays(30)->toDateString());
        $to = $this->normalizeDate($filters['to'] ?? null, $today);

        $rows = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->with(['proofs', 'branch'])
            ->orderByDesc('attendance_date')
            ->orderByDesc('time_in_at')
            ->get()
            ->map(fn (Attendance $attendance) => $this->serialize($attendance))
            ->values();

        $open = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('workflow_status', self::WORKFLOW_WORKING)
            ->with(['proofs', 'branch'])
            ->latest('time_in_at')
            ->first();

        $todayRecord = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('attendance_date', $today)
            ->with(['proofs', 'branch'])
            ->first();

        return [
            'personnel' => [
                'id' => $personnel->id,
                'code' => $personnel->code,
                'name' => $personnel->full_name,
                'status' => $personnel->status,
            ],
            'today' => $todayRecord ? $this->serialize($todayRecord) : null,
            'open_attendance' => $open ? $this->serialize($open) : null,
            'rows' => $rows,
            'filters' => ['from' => $from, 'to' => $to],
            'server_time' => $this->clock->now()->toIso8601String(),
        ];
    }

    public function myCalendar(User $actor, ?string $month = null): array
    {
        $personnel = $this->resolvePersonnel($actor, false);
        $today = CarbonImmutable::parse($this->clock->today(), $this->clock->timezone());

        try {
            $monthDate = $month
                ? CarbonImmutable::createFromFormat('Y-m', $month, $this->clock->timezone())->startOfMonth()
                : $today->startOfMonth();
        } catch (\Throwable) {
            $monthDate = $today->startOfMonth();
        }

        $from = $monthDate->startOfMonth();
        $to = $monthDate->endOfMonth();
        $scheduleMap = $this->workSchedules->resolveRange($personnel, $from, $to);

        $attendanceMap = Attendance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->with(['proofs', 'branch'])
            ->get()
            ->keyBy(fn (Attendance $attendance) => $attendance->attendance_date->toDateString());

        $requests = HrLeaveRequest::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->with('leaveType')
            ->get();

        $approvedLeaveMap = [];
        $pendingRequestMap = [];
        foreach ($requests as $request) {
            $requestFrom = CarbonImmutable::parse($request->start_date->toDateString(), $this->clock->timezone());
            $requestTo = CarbonImmutable::parse($request->end_date->toDateString(), $this->clock->timezone());
            for ($requestDate = $requestFrom; $requestDate->lessThanOrEqualTo($requestTo); $requestDate = $requestDate->addDay()) {
                $dateKey = $requestDate->toDateString();
                if ($dateKey < $from->toDateString() || $dateKey > $to->toDateString()) {
                    continue;
                }

                $payload = [
                    'id' => $request->id,
                    'code' => $request->code,
                    'request_type' => $request->request_type,
                    'leave_type' => $request->leaveType?->name,
                    'duration_type' => $request->duration_type,
                    'status' => $request->status,
                ];

                if ($request->status === 'APPROVED' && $request->request_type === 'LEAVE') {
                    $approvedLeaveMap[$dateKey] = $payload;
                } elseif ($request->status === 'PENDING') {
                    $pendingRequestMap[$dateKey] = $payload;
                }
            }
        }

        $days = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $schedule = $scheduleMap[$key];
            $attendance = $attendanceMap->get($key);
            $approvedLeave = $approvedLeaveMap[$key] ?? null;
            $calendarStatus = $approvedLeave
                ? 'LEAVE'
                : $this->calendarStatus($date, $today, $schedule, $attendance);

            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'weekday' => $date->format('D'),
                'calendar_status' => $calendarStatus,
                'schedule' => [
                    'configured' => (bool) $schedule['configured'],
                    'source' => $schedule['source'],
                    'is_working_day' => $schedule['is_working_day'],
                    'expected_hours' => $schedule['expected_minutes'] !== null
                        ? round(((int) $schedule['expected_minutes']) / 60, 2)
                        : null,
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'notes' => $schedule['notes'],
                ],
                'attendance' => $attendance ? $this->serialize($attendance) : null,
                'leave' => $approvedLeave,
                'pending_request' => $pendingRequestMap[$key] ?? null,
            ];
        }

        return [
            'month' => $from->format('Y-m'),
            'month_label' => $from->format('F Y'),
            'today' => $today->toDateString(),
            'schedule_configured' => collect($scheduleMap)->every(fn ($row) => $row['configured']),
            'days' => $days,
        ];
    }

    public function timeIn(User $actor, UploadedFile $proofFile): Attendance
    {
        $personnel = $this->resolvePersonnel($actor, true);
        $now = $this->clock->now();
        $today = $now->toDateString();
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $personnel, $now, $today, $proofFile, &$storedPath) {
                $open = Attendance::query()
                    ->where('business_id', $actor->business_id)
                    ->where('personnel_id', $personnel->id)
                    ->where('workflow_status', self::WORKFLOW_WORKING)
                    ->lockForUpdate()
                    ->first();

                if ($open) {
                    throw new DomainException('You already have an open Time In record. Time Out first before starting another attendance record.');
                }

                $existingToday = Attendance::query()
                    ->where('business_id', $actor->business_id)
                    ->where('personnel_id', $personnel->id)
                    ->whereDate('attendance_date', $today)
                    ->lockForUpdate()
                    ->first();

                if ($existingToday) {
                    throw new DomainException('Attendance for today already exists.');
                }

                $attendance = Attendance::query()->create([
                    'business_id' => $actor->business_id,
                    'personnel_id' => $personnel->id,
                    'branch_id' => $personnel->branch_id ?: $actor->default_branch_id,
                    'attendance_date' => $today,
                    'source' => 'SELF_SERVICE',
                    'workflow_status' => self::WORKFLOW_WORKING,
                    'attendance_status' => 'PENDING',
                    'time_in_at' => $now,
                    'worked_minutes' => 0,
                    'ot_minutes' => 0,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $storedPath = $this->storeProof($actor, $personnel, $attendance, 'TIME_IN', $proofFile, $now);

                $attendance->load(['personnel.position', 'branch', 'proofs']);

                $this->audit->record(
                    action: 'ATTENDANCE_TIME_IN',
                    module: 'my-attendance',
                    entityType: Attendance::class,
                    entityId: $attendance->id,
                    after: $this->auditSnapshot($attendance),
                    actor: $actor,
                );

                return $attendance;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function timeOut(User $actor, UploadedFile $proofFile): Attendance
    {
        $personnel = $this->resolvePersonnel($actor, true);
        $now = $this->clock->now();
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $personnel, $now, $proofFile, &$storedPath) {
                $attendance = Attendance::query()
                    ->where('business_id', $actor->business_id)
                    ->where('personnel_id', $personnel->id)
                    ->where('workflow_status', self::WORKFLOW_WORKING)
                    ->latest('time_in_at')
                    ->lockForUpdate()
                    ->first();

                if (! $attendance) {
                    throw new DomainException('No open Time In record was found.');
                }

                if (! $attendance->time_in_at || $now->lessThanOrEqualTo($attendance->time_in_at)) {
                    throw new DomainException('The Time In timestamp is invalid. Ask an administrator to review this attendance record.');
                }

                $workedMinutes = max(0, (int) round($attendance->time_in_at->diffInMinutes($now)));
                if ($workedMinutes > 24 * 60) {
                    throw new DomainException('This Time In has been open for more than 24 hours. Ask an administrator to review and adjust the attendance record before it can be completed.');
                }
                $otMinutes = $this->automaticOtMinutes($personnel, $attendance->time_in_at, $now);
                $before = $this->auditSnapshot($attendance);

                $storedPath = $this->storeProof($actor, $personnel, $attendance, 'TIME_OUT', $proofFile, $now);

                $attendance->update([
                    'time_out_at' => $now,
                    'worked_minutes' => $workedMinutes,
                    'ot_minutes' => $otMinutes,
                    'workflow_status' => self::WORKFLOW_COMPLETED,
                    'attendance_status' => 'PENDING',
                    'updated_by' => $actor->id,
                ]);

                $attendance->refresh()->load(['personnel.position', 'branch', 'proofs']);

                $this->audit->record(
                    action: 'ATTENDANCE_TIME_OUT',
                    module: 'my-attendance',
                    entityType: Attendance::class,
                    entityId: $attendance->id,
                    before: $before,
                    after: $this->auditSnapshot($attendance),
                    actor: $actor,
                );

                return $attendance;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function approve(User $actor, Attendance $attendance): Attendance
    {
        $this->assertOwned($actor, $attendance);

        return DB::transaction(function () use ($actor, $attendance) {
            $attendance = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($actor, $attendance);
            $this->assertNotPayrollLocked($attendance);

            if ($attendance->workflow_status !== self::WORKFLOW_COMPLETED) {
                throw new DomainException('Only completed attendance can be approved.');
            }

            $proofTypes = AttendanceProof::query()
                ->where('attendance_id', $attendance->id)
                ->pluck('proof_type')
                ->map(fn ($value) => strtoupper((string) $value));

            if ($attendance->source === 'SELF_SERVICE' && (! $proofTypes->contains('TIME_IN') || ! $proofTypes->contains('TIME_OUT'))) {
                throw new DomainException('Both Time In and Time Out proof photos are required before approval.');
            }

            $before = $this->auditSnapshot($attendance);
            $now = $this->clock->now();

            $attendance->update([
                'workflow_status' => self::WORKFLOW_APPROVED,
                'attendance_status' => 'PRESENT',
                'approved_by' => $actor->id,
                'approved_at' => $now,
                'updated_by' => $actor->id,
            ]);

            $attendance->refresh()->load(['personnel.position', 'branch', 'proofs', 'approver', 'adjuster']);
            $this->dailyCompensation->recalculatePersonnelDay(
                $attendance->personnel,
                $attendance->attendance_date->toDateString(),
            );

            $this->audit->record(
                action: 'APPROVE_ATTENDANCE',
                module: 'attendance',
                entityType: Attendance::class,
                entityId: $attendance->id,
                before: $before,
                after: $this->auditSnapshot($attendance),
                actor: $actor,
            );

            return $attendance;
        });
    }

    public function adjust(User $actor, Attendance $attendance, array $data): Attendance
    {
        $this->assertOwned($actor, $attendance);

        return DB::transaction(function () use ($actor, $attendance, $data) {
            $attendance = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($actor, $attendance);
            $this->assertNotPayrollLocked($attendance);

            $timezone = $this->clock->timezone();
            $date = $attendance->attendance_date->toDateString();
            $timeIn = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$data['time_in'], $timezone);
            $timeOut = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$data['time_out'], $timezone);

            if ($timeOut->lessThanOrEqualTo($timeIn)) {
                $timeOut = $timeOut->addDay();
            }

            $workedMinutes = max(0, (int) round($timeIn->diffInMinutes($timeOut)));
            if ($workedMinutes > 24 * 60) {
                throw new DomainException('Attendance cannot exceed 24 hours in one record. Review the dates/times or split the attendance into the correct workday.');
            }
            $personnel = $attendance->personnel()->firstOrFail();

            $maxEligibleOtMinutes = $this->automaticOtMinutes($personnel, $timeIn, $timeOut);
            if (array_key_exists('ot_hours', $data) && $data['ot_hours'] !== null && $data['ot_hours'] !== '') {
                $requestedOtMinutes = max(0, (int) round(((float) $data['ot_hours']) * 60));
                $otMinutes = $personnel->ot_entitled ? min($maxEligibleOtMinutes, $requestedOtMinutes) : 0;
            } else {
                $otMinutes = $maxEligibleOtMinutes;
            }

            $before = $this->auditSnapshot($attendance);
            $now = $this->clock->now();

            $attendance->update([
                'original_time_in_at' => $attendance->original_time_in_at ?: $attendance->time_in_at,
                'original_time_out_at' => $attendance->original_time_out_at ?: $attendance->time_out_at,
                'time_in_at' => $timeIn,
                'time_out_at' => $timeOut,
                'worked_minutes' => $workedMinutes,
                'ot_minutes' => $otMinutes,
                'workflow_status' => self::WORKFLOW_ADJUSTED,
                'attendance_status' => 'PRESENT',
                'adjustment_reason' => trim($data['reason']),
                'adjusted_by' => $actor->id,
                'adjusted_at' => $now,
                'approved_by' => $actor->id,
                'approved_at' => $now,
                'updated_by' => $actor->id,
            ]);

            $attendance->refresh()->load(['personnel.position', 'branch', 'proofs', 'approver', 'adjuster']);
            $this->dailyCompensation->recalculatePersonnelDay(
                $attendance->personnel,
                $attendance->attendance_date->toDateString(),
            );

            $this->audit->record(
                action: 'ADJUST_ATTENDANCE',
                module: 'attendance',
                entityType: Attendance::class,
                entityId: $attendance->id,
                before: $before,
                after: $this->auditSnapshot($attendance),
                reason: trim($data['reason']),
                actor: $actor,
            );

            return $attendance;
        });
    }

    public function proofFor(User $actor, Attendance $attendance, string $type): AttendanceProof
    {
        $this->assertOwned($actor, $attendance);
        $type = strtoupper(trim($type));

        if (! in_array($type, ['TIME_IN', 'TIME_OUT'], true)) {
            abort(404);
        }

        return AttendanceProof::query()
            ->where('attendance_id', $attendance->id)
            ->where('business_id', $actor->business_id)
            ->where('proof_type', $type)
            ->firstOrFail();
    }

    public function serialize(Attendance $attendance): array
    {
        $attendance->loadMissing(['personnel.position', 'branch', 'proofs', 'approver', 'adjuster']);
        $proofTypes = $attendance->proofs->pluck('proof_type')->map(fn ($value) => strtoupper((string) $value));

        return [
            'id' => $attendance->id,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'personnel' => $attendance->personnel ? [
                'id' => $attendance->personnel->id,
                'code' => $attendance->personnel->code,
                'name' => $attendance->personnel->full_name,
                'position' => $attendance->personnel->position?->display_value,
            ] : null,
            'branch' => $attendance->branch ? [
                'id' => $attendance->branch->id,
                'code' => $attendance->branch->code,
                'name' => $attendance->branch->name,
            ] : null,
            'source' => $attendance->source,
            'workflow_status' => $attendance->workflow_status,
            'attendance_status' => $attendance->attendance_status,
            'time_in_at' => $this->iso($attendance->time_in_at),
            'time_out_at' => $this->iso($attendance->time_out_at),
            'time_in_value' => $this->timeValue($attendance->time_in_at),
            'time_out_value' => $this->timeValue($attendance->time_out_at),
            'worked_hours' => round(((int) $attendance->worked_minutes) / 60, 2),
            'ot_hours' => round(((int) $attendance->ot_minutes) / 60, 2),
            'proofs' => [
                'time_in' => $proofTypes->contains('TIME_IN'),
                'time_out' => $proofTypes->contains('TIME_OUT'),
            ],
            'approved_by' => $attendance->approver?->display_name,
            'approved_at' => $this->iso($attendance->approved_at),
            'adjustment_reason' => $attendance->adjustment_reason,
            'adjusted_by' => $attendance->adjuster?->display_name,
            'adjusted_at' => $this->iso($attendance->adjusted_at),
            'payroll_locked' => $attendance->payroll_locked_at !== null,
        ];
    }

    private function calendarStatus(
        CarbonImmutable $date,
        CarbonImmutable $today,
        array $schedule,
        ?Attendance $attendance,
    ): string {
        if ($attendance) {
            if (in_array($attendance->workflow_status, [self::WORKFLOW_APPROVED, self::WORKFLOW_ADJUSTED], true)) {
                $expected = (int) ($schedule['expected_minutes'] ?? 0);
                if ($expected > 0 && (int) $attendance->worked_minutes < $expected) {
                    return 'NOT_FULL_DAY';
                }
                return 'PRESENT';
            }

            if ($attendance->workflow_status === self::WORKFLOW_WORKING) {
                return 'WORKING';
            }

            if ($attendance->workflow_status === self::WORKFLOW_COMPLETED) {
                return 'PENDING_APPROVAL';
            }
        }

        if (! $schedule['configured']) {
            return 'UNSCHEDULED';
        }

        if (! $schedule['is_working_day']) {
            return 'DAY_OFF';
        }

        if ($date->lessThan($today)) {
            return 'ABSENT';
        }

        return $date->isSameDay($today) ? 'TODAY' : 'FUTURE';
    }

    private function resolvePersonnel(User $actor, bool $requireActive): Personnel
    {
        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('linked_user_id', $actor->id)
            ->first();

        if (! $personnel) {
            throw new DomainException('Your user account is not linked to a Personnel record. Ask an administrator to link your account first.');
        }

        if ($requireActive && strtoupper((string) $personnel->status) !== 'ACTIVE') {
            throw new DomainException('Your Personnel record is inactive.');
        }

        return $personnel;
    }

    private function storeProof(
        User $actor,
        Personnel $personnel,
        Attendance $attendance,
        string $proofType,
        UploadedFile $file,
        CarbonImmutable $capturedAt,
    ): string {
        $folderName = trim(preg_replace('/[^A-Za-z0-9 _().-]/u', '', $personnel->full_name.' ('.$personnel->code.')'));
        $folderName = $folderName !== '' ? $folderName : $personnel->code;
        $folder = sprintf(
            'attendance-proofs/%s/%s/%s/%s',
            $attendance->attendance_date->format('Y'),
            $attendance->attendance_date->format('m'),
            $folderName,
            $attendance->id,
        );

        $extension = match (strtolower((string) $file->getMimeType())) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $fileName = strtolower(str_replace('_', '-', $proofType)).'-'.Str::uuid().'.'.$extension;
        $path = $file->storeAs($folder, $fileName, 'local');

        if (! $path) {
            throw new DomainException('Unable to store the attendance proof photo.');
        }

        AttendanceProof::query()->create([
            'attendance_id' => $attendance->id,
            'business_id' => $actor->business_id,
            'proof_type' => $proofType,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'captured_at' => $capturedAt,
            'uploaded_by' => $actor->id,
        ]);

        return $path;
    }

    private function automaticOtMinutes(Personnel $personnel, CarbonImmutable $timeIn, CarbonImmutable $timeOut): int
    {
        if (! $personnel->ot_entitled || $timeOut->lessThanOrEqualTo($timeIn)) {
            return 0;
        }

        $workedMinutes = max(0, (int) round($timeIn->diffInMinutes($timeOut)));
        $schedule = $this->workSchedules->resolveForDate($personnel, $timeIn->startOfDay());

        if (! ($schedule['configured'] ?? false) || ! ($schedule['is_working_day'] ?? false)) {
            return 0;
        }

        $expectedMinutes = max(0, (int) ($schedule['expected_minutes'] ?? 0));
        $startTime = $schedule['start_time'] ?? null;
        $endTime = $schedule['end_time'] ?? null;

        if ($startTime && $endTime) {
            $dateKey = $timeIn->setTimezone($this->clock->timezone())->toDateString();
            $scheduledStart = CarbonImmutable::createFromFormat('Y-m-d H:i', $dateKey.' '.$startTime, $this->clock->timezone());
            $scheduledEnd = CarbonImmutable::createFromFormat('Y-m-d H:i', $dateKey.' '.$endTime, $this->clock->timezone());
            if ($scheduledEnd->lessThanOrEqualTo($scheduledStart)) {
                $scheduledEnd = $scheduledEnd->addDay();
            }

            // Time before scheduled start is never OT. Only work after scheduled end can qualify.
            if ($timeOut->lessThanOrEqualTo($scheduledEnd)) {
                return 0;
            }

            return min($workedMinutes, max(0, (int) round($scheduledEnd->diffInMinutes($timeOut))));
        }

        return $expectedMinutes > 0 ? max(0, $workedMinutes - $expectedMinutes) : 0;
    }

    private function assertOwned(User $actor, Attendance $attendance): void
    {
        if ((int) $attendance->business_id !== (int) $actor->business_id) {
            abort(404);
        }
    }

    private function assertNotPayrollLocked(Attendance $attendance): void
    {
        if ($attendance->payroll_locked_at) {
            throw new DomainException('This attendance is already tied to payroll and cannot be changed from Attendance. Use the payroll adjustment workflow instead.');
        }
    }

    private function normalizeDate(?string $value, string $fallback): string
    {
        if (! $value) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value, $this->clock->timezone())->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function iso($value): ?string
    {
        return $value?->timezone($this->clock->timezone())->toIso8601String();
    }

    private function timeValue($value): ?string
    {
        return $value?->timezone($this->clock->timezone())->format('H:i');
    }

    private function auditSnapshot(Attendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'personnel_id' => $attendance->personnel_id,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'source' => $attendance->source,
            'workflow_status' => $attendance->workflow_status,
            'attendance_status' => $attendance->attendance_status,
            'time_in_at' => $this->iso($attendance->time_in_at),
            'time_out_at' => $this->iso($attendance->time_out_at),
            'worked_minutes' => (int) $attendance->worked_minutes,
            'ot_minutes' => (int) $attendance->ot_minutes,
            'adjustment_reason' => $attendance->adjustment_reason,
            'payroll_locked_at' => $this->iso($attendance->payroll_locked_at),
        ];
    }
}
