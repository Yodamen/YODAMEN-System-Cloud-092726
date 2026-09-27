<?php

namespace App\Services\Hr;

use App\Models\Personnel;
use App\Models\PersonnelScheduleOverride;
use App\Models\PersonnelWorkSchedule;
use App\Models\User;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkScheduleService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function get(User $actor, Personnel $personnel): array
    {
        $this->assertOwned($actor, $personnel);

        $weekly = PersonnelWorkSchedule::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->orderBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $weeklyRows = collect(range(1, 7))->map(function (int $day) use ($weekly) {
            $row = $weekly->get($day);
            return [
                'day_of_week' => $day,
                'is_working_day' => (bool) ($row?->is_working_day ?? false),
                'expected_minutes' => (int) ($row?->expected_minutes ?? 0),
                'start_time' => $this->timeValue($row?->start_time),
                'end_time' => $this->timeValue($row?->end_time),
            ];
        })->values();

        $overrides = PersonnelScheduleOverride::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('schedule_date', '>=', now()->subMonths(6)->toDateString())
            ->orderBy('schedule_date')
            ->get()
            ->map(fn (PersonnelScheduleOverride $row) => [
                'id' => $row->id,
                'schedule_date' => $row->schedule_date->toDateString(),
                'schedule_type' => $row->schedule_type,
                'expected_minutes' => (int) $row->expected_minutes,
                'start_time' => $this->timeValue($row->start_time),
                'end_time' => $this->timeValue($row->end_time),
                'notes' => $row->notes,
                'source_type' => $row->source_type ?: 'MANUAL',
                'source_reference_id' => $row->source_reference_id,
                'locked' => $row->source_type === 'LEAVE_REQUEST',
            ])->values();

        return [
            'personnel' => [
                'id' => $personnel->id,
                'code' => $personnel->code,
                'name' => $personnel->full_name,
            ],
            'configured' => $weekly->count() === 7,
            'weekly' => $weeklyRows,
            'overrides' => $overrides,
        ];
    }

    public function save(User $actor, Personnel $personnel, array $data): array
    {
        $this->assertOwned($actor, $personnel);

        DB::transaction(function () use ($actor, $personnel, $data) {
            foreach ($data['weekly'] as $row) {
                $working = (bool) $row['is_working_day'];
                PersonnelWorkSchedule::query()->updateOrCreate(
                    ['personnel_id' => $personnel->id, 'day_of_week' => (int) $row['day_of_week']],
                    [
                        'business_id' => $actor->business_id,
                        'is_working_day' => $working,
                        'expected_minutes' => $working ? (int) $row['expected_minutes'] : 0,
                        'start_time' => $working ? ($row['start_time'] ?? null) : null,
                        'end_time' => $working ? ($row['end_time'] ?? null) : null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]
                );
            }

            $incomingDates = collect($data['overrides'])->pluck('schedule_date')->all();
            PersonnelScheduleOverride::query()
                ->where('business_id', $actor->business_id)
                ->where('personnel_id', $personnel->id)
                ->whereDate('schedule_date', '>=', now()->startOfMonth()->toDateString())
                ->where(function ($query) {
                    $query->whereNull('source_type')->orWhere('source_type', 'MANUAL');
                })
                ->when($incomingDates, fn ($query) => $query->whereNotIn('schedule_date', $incomingDates))
                ->delete();

            foreach ($data['overrides'] as $row) {
                $workday = strtoupper($row['schedule_type']) === 'WORKDAY';
                $protected = PersonnelScheduleOverride::query()
                    ->where('personnel_id', $personnel->id)
                    ->whereDate('schedule_date', $row['schedule_date'])
                    ->where('source_type', 'LEAVE_REQUEST')
                    ->exists();
                if ($protected) {
                    throw new DomainException('An approved HR request already controls the schedule on '.$row['schedule_date'].'. Manage it from Leave Management.');
                }

                PersonnelScheduleOverride::query()->updateOrCreate(
                    ['personnel_id' => $personnel->id, 'schedule_date' => $row['schedule_date']],
                    [
                        'business_id' => $actor->business_id,
                        'schedule_type' => $workday ? 'WORKDAY' : 'DAY_OFF',
                        'expected_minutes' => $workday ? (int) $row['expected_minutes'] : 0,
                        'start_time' => $workday ? ($row['start_time'] ?? null) : null,
                        'end_time' => $workday ? ($row['end_time'] ?? null) : null,
                        'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                        'source_type' => 'MANUAL',
                        'source_reference_id' => null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]
                );
            }

            $this->audit->record(
                action: 'UPDATE_WORK_SCHEDULE',
                module: 'employees',
                entityType: Personnel::class,
                entityId: $personnel->id,
                after: ['weekly' => $data['weekly'], 'overrides' => $data['overrides']],
                actor: $actor,
            );
        });

        return $this->get($actor, $personnel);
    }

    public function resolveRange(Personnel $personnel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weekly = PersonnelWorkSchedule::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->get()
            ->keyBy('day_of_week');

        $overrides = PersonnelScheduleOverride::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('schedule_date', '>=', $from->toDateString())
            ->whereDate('schedule_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (PersonnelScheduleOverride $row) => $row->schedule_date->toDateString());

        $result = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $override = $overrides->get($key);

            if ($override) {
                $result[$key] = [
                    'configured' => true,
                    'source' => 'OVERRIDE',
                    'is_working_day' => $override->schedule_type === 'WORKDAY',
                    'expected_minutes' => (int) $override->expected_minutes,
                    'start_time' => $this->timeValue($override->start_time),
                    'end_time' => $this->timeValue($override->end_time),
                    'notes' => $override->notes,
                    'source_type' => $override->source_type ?: 'MANUAL',
                    'source_reference_id' => $override->source_reference_id,
                ];
                continue;
            }

            $row = $weekly->get($date->isoWeekday());
            $result[$key] = $row ? [
                'configured' => true,
                'source' => 'WEEKLY',
                'is_working_day' => (bool) $row->is_working_day,
                'expected_minutes' => (int) $row->expected_minutes,
                'start_time' => $this->timeValue($row->start_time),
                'end_time' => $this->timeValue($row->end_time),
                'notes' => null,
                'source_type' => 'WEEKLY',
                'source_reference_id' => null,
            ] : [
                'configured' => false,
                'source' => 'NONE',
                'is_working_day' => null,
                'expected_minutes' => null,
                'start_time' => null,
                'end_time' => null,
                'notes' => null,
                'source_type' => 'NONE',
                'source_reference_id' => null,
            ];
        }

        return $result;
    }

    public function resolveForDate(Personnel $personnel, CarbonImmutable|string $date): array
    {
        $date = $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);

        $override = PersonnelScheduleOverride::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('schedule_date', $date->toDateString())
            ->first();

        if ($override) {
            return [
                'configured' => true,
                'source' => 'OVERRIDE',
                'is_working_day' => $override->schedule_type === 'WORKDAY',
                'expected_minutes' => (int) $override->expected_minutes,
                'start_time' => $this->timeValue($override->start_time),
                'end_time' => $this->timeValue($override->end_time),
                'notes' => $override->notes,
                'source_type' => $override->source_type ?: 'MANUAL',
                'source_reference_id' => $override->source_reference_id,
            ];
        }

        $weekly = PersonnelWorkSchedule::query()
            ->where('business_id', $personnel->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('day_of_week', $date->isoWeekday())
            ->first();

        if (! $weekly) {
            return [
                'configured' => false,
                'source' => 'NONE',
                'is_working_day' => null,
                'expected_minutes' => null,
                'start_time' => null,
                'end_time' => null,
                'notes' => null,
                'source_type' => 'NONE',
                'source_reference_id' => null,
            ];
        }

        return [
            'configured' => true,
            'source' => 'WEEKLY',
            'is_working_day' => (bool) $weekly->is_working_day,
            'expected_minutes' => (int) $weekly->expected_minutes,
            'start_time' => $this->timeValue($weekly->start_time),
            'end_time' => $this->timeValue($weekly->end_time),
            'notes' => null,
            'source_type' => 'WEEKLY',
            'source_reference_id' => null,
        ];
    }

    private function assertOwned(User $actor, Personnel $personnel): void
    {
        if ((int) $personnel->business_id !== (int) $actor->business_id) {
            abort(404);
        }
    }

    private function timeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
    }
}
