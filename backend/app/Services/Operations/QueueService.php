<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueueService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
    ) {
    }

    public function checkInBooking(User $actor, Booking $booking): QueueEntry
    {
        if ($booking->business_id !== $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $booking) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->business_id !== $actor->business_id) abort(404);

            $existing = QueueEntry::query()->where('booking_id', $booking->id)->first();
            if ($existing) {
                return $this->loadEntry($existing);
            }

            if (! $booking->canCheckIn()) {
                throw new DomainException('Only booked reservations can be checked in.');
            }

            $booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines']);
            $queue = $this->createFromBooking($actor, $booking);

            $booking->update(['status' => 'CHECKED_IN', 'updated_by' => $actor->id]);

            $this->audit->record(
                action: 'CHECK_IN', module: 'queue', entityType: QueueEntry::class, entityId: $queue->id,
                after: $this->snapshot($queue), actor: $actor,
                metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code],
            );

            return $queue;
        });
    }

    public function createWalkIn(User $actor, array $data): QueueEntry
    {
        return DB::transaction(function () use ($actor, $data) {
            $booking = $this->bookings->createWalkIn($actor, $data);
            $queue = $this->createFromBooking($actor, $booking);

            $this->audit->record(
                action: 'CREATE_WALK_IN_QUEUE', module: 'queue', entityType: QueueEntry::class, entityId: $queue->id,
                after: $this->snapshot($queue), actor: $actor,
                metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code],
            );

            return $queue;
        });
    }

    public function cancel(User $actor, QueueEntry $queue, string $reason): QueueEntry
    {
        if ($queue->business_id !== $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $queue, $reason) {
            $queue = QueueEntry::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->business_id !== $actor->business_id) abort(404);
            if (! $queue->isWaiting()) {
                throw new DomainException('Only waiting queue entries can be cancelled.');
            }

            $queue->load(['booking', 'customer', 'serviceLines', 'retailLines']);
            $before = $this->snapshot($queue);
            $now = $this->clock->now();

            $queue->update([
                'status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $actor->id,
                'cancellation_reason' => trim($reason), 'updated_by' => $actor->id,
            ]);

            $booking = Booking::query()->whereKey($queue->booking_id)->lockForUpdate()->firstOrFail();
            $booking->update([
                'status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $actor->id,
                'cancellation_reason' => trim($reason), 'updated_by' => $actor->id,
            ]);

            $queue = $this->loadEntry($queue->fresh());
            $this->audit->record(
                action: 'CANCEL', module: 'queue', entityType: QueueEntry::class, entityId: $queue->id,
                before: $before, after: $this->snapshot($queue), reason: $reason, actor: $actor,
                metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code],
            );

            return $queue;
        });
    }

    public function entriesForDate(User $actor, string $date, string $status = 'WAITING'): Collection
    {
        $status = strtoupper(trim($status));
        $query = QueueEntry::query()
            ->with(['customer', 'booking.payments', 'branch', 'visit', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines'])
            ->where('business_id', $actor->business_id)
            ->whereDate('queue_date', $date);

        if ($actor->default_branch_id) $query->where('branch_id', $actor->default_branch_id);
        if ($status !== 'ALL') $query->where('status', $status);

        $rows = $query->get();
        if ($status !== 'WAITING') {
            return $rows->sortByDesc(fn (QueueEntry $row) => $row->cancelled_at?->getTimestamp() ?? $row->check_in_at->getTimestamp())->values();
        }

        return $this->applyLegacyPriority($rows);
    }

    public function snapshot(QueueEntry $queue): array
    {
        return [
            'id' => $queue->id, 'code' => $queue->code, 'booking_id' => $queue->booking_id,
            'booking_code' => $queue->booking?->code, 'customer_id' => $queue->customer_id,
            'customer_code' => $queue->customer?->code, 'source' => $queue->source, 'status' => $queue->status,
            'queue_date' => $queue->queue_date?->format('Y-m-d'), 'check_in_at' => $queue->check_in_at?->toIso8601String(),
            'scheduled_date' => $queue->scheduled_date?->format('Y-m-d'), 'scheduled_time' => $queue->scheduled_time,
            'services' => $queue->serviceLines->map(fn ($line) => [
                'code' => $line->code, 'service_id' => $line->service_id, 'service_name' => $line->service_name_snapshot,
                'quantity' => $line->quantity, 'assigned_personnel_id' => $line->assigned_personnel_id,
            ])->values()->all(),
            'retail_items' => $queue->retailLines->map(fn ($line) => [
                'code' => $line->code, 'item_name' => $line->item_name_snapshot, 'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
            ])->values()->all(),
            'cancellation_reason' => $queue->cancellation_reason,
        ];
    }

    private function createFromBooking(User $actor, Booking $booking): QueueEntry
    {
        $booking->loadMissing(['customer', 'serviceLines.assignedPersonnel', 'retailLines']);
        if (! $booking->serviceLines->count() && ! $booking->retailLines->count()) {
            throw new DomainException('A queue entry requires at least one service or retail line.');
        }

        $now = $this->clock->now();
        $source = strtoupper((string) $booking->booking_source);
        $queue = QueueEntry::query()->create([
            'business_id' => $actor->business_id,
            'branch_id' => $booking->branch_id,
            'code' => $this->codes->next('QUEUE:'.$now->format('my'), 'QUE-'.$now->format('my').'-', 6, $actor->business_id),
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'customer_type' => $booking->customer_type,
            'queue_date' => $now->toDateString(),
            'check_in_at' => $now,
            'source' => $source,
            'status' => 'WAITING',
            'booking_created_at' => $booking->created_at,
            'scheduled_date' => $source === 'RESERVATION' ? $booking->booking_date?->format('Y-m-d') : null,
            'scheduled_time' => $source === 'RESERVATION' ? $booking->booking_time : null,
            'notes' => $booking->notes,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($booking->serviceLines as $index => $line) {
            $queue->serviceLines()->create([
                'booking_service_line_id' => $line->id,
                'code' => $queue->code.'-QS-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'service_id' => $line->service_id,
                'service_name_snapshot' => $line->service_name_snapshot,
                'source_type' => $line->source_type,
                'source_reference_code' => $line->source_reference_code,
                'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name_snapshot' => $line->assignedPersonnel
                    ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null,
                'status' => 'PENDING',
                'notes' => $line->notes,
                'sort_order' => $line->sort_order,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        foreach ($booking->retailLines as $index => $line) {
            $queue->retailLines()->create([
                'booking_retail_line_id' => $line->id,
                'code' => $queue->code.'-QR-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'legacy_retail_item_code' => $line->legacy_retail_item_code,
                'item_name_snapshot' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price_snapshot' => $line->unit_price_snapshot,
                'notes' => $line->notes,
                'sort_order' => $line->sort_order,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        return $this->loadEntry($queue);
    }

    private function loadEntry(QueueEntry $queue): QueueEntry
    {
        return $queue->load(['customer', 'booking.payments', 'branch', 'visit', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines']);
    }

    private function applyLegacyPriority(Collection $rows): Collection
    {
        $walkIns = $rows->where('source', 'WALK_IN')->sortBy(fn (QueueEntry $r) => $r->check_in_at->getTimestamp())->values();
        $bands = [];

        foreach ($rows->where('source', 'RESERVATION') as $reservation) {
            $created = $reservation->booking_created_at?->getTimestamp() ?? 0;
            $band = 0;
            foreach ($walkIns as $walkIn) {
                if ($created && $walkIn->check_in_at->getTimestamp() < $created) $band++;
            }
            $bands[$band][] = $reservation;
        }

        foreach ($bands as &$bandRows) {
            usort($bandRows, function (QueueEntry $a, QueueEntry $b) {
                $aSchedule = $this->scheduleTimestamp($a);
                $bSchedule = $this->scheduleTimestamp($b);
                if ($aSchedule !== $bSchedule) return $aSchedule <=> $bSchedule;
                $aCreated = $a->booking_created_at?->getTimestamp() ?? 0;
                $bCreated = $b->booking_created_at?->getTimestamp() ?? 0;
                if ($aCreated !== $bCreated) return $aCreated <=> $bCreated;
                return $a->check_in_at->getTimestamp() <=> $b->check_in_at->getTimestamp();
            });
        }
        unset($bandRows);

        $ordered = collect();
        for ($i = 0; $i <= $walkIns->count(); $i++) {
            foreach ($bands[$i] ?? [] as $reservation) $ordered->push($reservation);
            if ($i < $walkIns->count()) $ordered->push($walkIns[$i]);
        }

        return $ordered->values()->each(function (QueueEntry $row, int $index) {
            $row->setAttribute('priority_no', $index + 1);
        });
    }

    private function scheduleTimestamp(QueueEntry $queue): int
    {
        if (! $queue->scheduled_date || ! $queue->scheduled_time) return 0;
        return CarbonImmutable::parse(
            $queue->scheduled_date->format('Y-m-d').' '.substr((string) $queue->scheduled_time, 0, 8),
            $this->clock->timezone()
        )->getTimestamp();
    }
}
