<?php

namespace App\Services\Sales;

use App\Models\User;
use App\Models\ZReading;
use App\Services\Foundation\BusinessClock;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ZReadingService
{
    public function __construct(
        private readonly SalesReportingService $reports,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {
    }

    public function preview(User $actor, int $branchId, string $date): array
    {
        $preview = $this->reports->daily($actor, $branchId, $date);
        $reading = ZReading::query()
            ->where('business_id', $actor->business_id)
            ->where('branch_id', $preview['branch']['id'])
            ->whereDate('business_date', $preview['business_date'])
            ->first();

        return ['preview' => $preview, 'z_reading' => $reading ? $this->serialize($reading) : null];
    }

    public function close(User $actor, int $branchId, string $date): ZReading
    {
        $preview = $this->reports->daily($actor, $branchId, $date);

        return DB::transaction(function () use ($actor, $preview) {
            $reading = ZReading::query()->where('business_id', $actor->business_id)
                ->where('branch_id', $preview['branch']['id'])
                ->whereDate('business_date', $preview['business_date'])
                ->lockForUpdate()->first();

            if ($reading && $reading->status === 'CLOSED') {
                throw new DomainException('This business date is already closed.');
            }

            $before = $reading ? $this->serialize($reading) : null;
            $reading ??= new ZReading([
                'business_id' => $actor->business_id,
                'branch_id' => $preview['branch']['id'],
                'business_date' => $preview['business_date'],
            ]);
            $reading->fill([
                'status' => 'CLOSED',
                'snapshot' => $preview,
                'closed_at' => $this->clock->now(),
                'closed_by' => $actor->id,
                'reopened_at' => null,
                'reopened_by' => null,
                'reopen_reason' => null,
            ])->save();

            $this->audit->record(
                action: 'CLOSE_Z_READING', module: 'sales', entityType: ZReading::class, entityId: $reading->id,
                before: $before, after: $this->serialize($reading), actor: $actor,
            );

            return $reading->fresh();
        });
    }

    public function reopen(User $actor, ZReading $reading, string $reason): ZReading
    {
        if ((int) $reading->business_id !== (int) $actor->business_id) abort(404);
        if ($actor->default_branch_id && (int) $actor->default_branch_id !== (int) $reading->branch_id) abort(404);
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reopen reason is required.');
        if ($reading->status !== 'CLOSED') throw new DomainException('Only a Closed Z-Reading can be reopened.');

        return DB::transaction(function () use ($actor, $reading, $reason) {
            $reading = ZReading::query()->whereKey($reading->id)->lockForUpdate()->firstOrFail();
            if ($reading->status !== 'CLOSED') throw new DomainException('Only a Closed Z-Reading can be reopened.');
            $before = $this->serialize($reading);
            $reading->update([
                'status' => 'REOPENED',
                'reopened_at' => $this->clock->now(),
                'reopened_by' => $actor->id,
                'reopen_reason' => $reason,
            ]);

            $this->audit->record(
                action: 'REOPEN_Z_READING', module: 'sales', entityType: ZReading::class, entityId: $reading->id,
                before: $before, after: $this->serialize($reading->fresh()), reason: $reason, actor: $actor,
            );

            return $reading->fresh();
        });
    }

    public function serialize(ZReading $reading): array
    {
        return [
            'id' => $reading->id,
            'branch_id' => $reading->branch_id,
            'business_date' => $reading->business_date?->toDateString(),
            'status' => $reading->status,
            'closed_at' => $reading->closed_at?->toIso8601String(),
            'closed_by' => $reading->closed_by,
            'reopened_at' => $reading->reopened_at?->toIso8601String(),
            'reopened_by' => $reading->reopened_by,
            'reopen_reason' => $reading->reopen_reason,
        ];
    }
}
