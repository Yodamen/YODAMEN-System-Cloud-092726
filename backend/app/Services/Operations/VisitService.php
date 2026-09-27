<?php

namespace App\Services\Operations;

use App\Models\Personnel;
use App\Models\RetailProduct;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\ServiceCompletionEvent;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Models\VisitRetailLine;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Inventory\ServiceConsumptionPostingService;
use App\Services\Packages\PackageServiceCreditService;
use App\Services\Security\AuditService;
use App\Services\Sales\SaleDraftService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VisitService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
        private readonly SaleDraftService $sales,
        private readonly PackageServiceCreditService $packages,
        private readonly ServiceConsumptionPostingService $serviceConsumption,
    ) {
    }

    public function startFromQueue(User $actor, QueueEntry $queue): Visit
    {
        if ($queue->business_id !== $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $queue) {
            $queue = QueueEntry::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->business_id !== $actor->business_id) abort(404);

            $existing = Visit::query()->where('queue_entry_id', $queue->id)->first();
            if ($existing) {
                return $this->loadVisit($existing);
            }

            if (! $queue->isWaiting()) {
                throw new DomainException('Only waiting queue entries can start servicing.');
            }

            $queue->load(['booking', 'customer', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel']);
            $activeServices = $queue->serviceLines
                ->filter(fn ($line) => strtoupper((string) $line->status) !== 'CANCELLED')
                ->values();

            if ($activeServices->isEmpty() && $queue->retailLines->isEmpty()) {
                throw new DomainException('Add at least one active service or retail purchase before starting servicing.');
            }

            $now = $this->clock->now();
            $visit = Visit::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $queue->branch_id,
                'code' => $this->codes->next(
                    'VISIT:'.$now->format('my'),
                    'VIS-'.$now->format('my').'-',
                    6,
                    $actor->business_id,
                ),
                'queue_entry_id' => $queue->id,
                'booking_id' => $queue->booking_id,
                'customer_id' => $queue->customer_id,
                'customer_type' => $queue->customer_type,
                'visit_date' => $now->toDateString(),
                'check_in_at' => $queue->check_in_at,
                'service_start_at' => $now,
                'status' => 'IN_SERVICE',
                'notes' => $queue->notes,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($activeServices as $index => $line) {
                $visitLine = $visit->serviceLines()->create([
                    'queue_service_line_id' => $line->id,
                    'booking_service_line_id' => $line->booking_service_line_id,
                    'code' => $visit->code.'-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'service_id' => $line->service_id,
                    'service_name_snapshot' => $line->service_name_snapshot,
                    'source_type' => $line->source_type,
                    'source_reference_code' => $line->source_reference_code,
                    'package_enrollment_id' => $line->package_enrollment_id,
                    'service_credit_id' => $line->service_credit_id,
                    'quantity' => max(1, (int) $line->quantity),
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'assigned_personnel_name_snapshot' => $line->assigned_personnel_name_snapshot,
                    'status' => 'IN_PROGRESS',
                    'started_at' => $now,
                    'notes' => $line->notes,
                    'sort_order' => $line->sort_order ?: (($index + 1) * 10),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->packages->linkVisitLine($actor, $visit, $visitLine);
            }

            foreach ($queue->retailLines as $index => $line) {
                $visit->retailLines()->create([
                    'queue_retail_line_id' => $line->id,
                    'booking_retail_line_id' => $line->booking_retail_line_id,
                    'code' => $visit->code.'-R'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'legacy_retail_item_code' => $line->legacy_retail_item_code,
                    'retail_product_id' => $line->retail_product_id,
                    'sku_snapshot' => $line->sku_snapshot,
                    'item_name_snapshot' => $line->item_name_snapshot,
                    'quantity' => max(1, (int) $line->quantity),
                    'unit_price_snapshot' => $line->unit_price_snapshot,
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'assigned_personnel_name_snapshot' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
                    'notes' => $line->notes,
                    'sort_order' => $line->sort_order ?: (($index + 1) * 10),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $queue->update(['status' => 'SERVICING', 'updated_by' => $actor->id]);
            $queue->booking()->update(['status' => 'ON_GOING', 'updated_by' => $actor->id]);

            $visit = $this->loadVisit($visit);
            $this->audit->record(
                action: 'START_SERVICING',
                module: 'servicing',
                entityType: Visit::class,
                entityId: $visit->id,
                after: $this->snapshot($visit),
                actor: $actor,
                metadata: ['queue_entry_id' => $queue->id, 'queue_code' => $queue->code, 'booking_id' => $queue->booking_id],
            );

            return $visit;
        });
    }

    public function entriesForDate(User $actor, string $date, string $status = 'IN_SERVICE'): Collection
    {
        $status = strtoupper(trim($status));
        $query = Visit::query()
            ->with($this->relations())
            ->where('business_id', $actor->business_id)
            ->whereDate('visit_date', $date);

        if ($actor->default_branch_id) $query->where('branch_id', $actor->default_branch_id);
        if ($status !== 'ALL') $query->where('status', $status);

        return $query->orderBy('service_start_at')->orderBy('id')->get();
    }

    public function addService(User $actor, Visit $visit, array $data): Visit
    {
        $this->assertOwnedAndOpen($actor, $visit);

        return DB::transaction(function () use ($actor, $visit, $data) {
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndOpen($actor, $visit);

            $service = Service::query()
                ->with(['statusReference', 'serviceCount'])
                ->where('business_id', $actor->business_id)
                ->whereKey($data['service_id'])
                ->firstOrFail();

            if (strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
                throw new DomainException('Inactive services cannot be added to an active Visit.');
            }

            $personnel = $this->resolvePersonnel($actor, $data['assigned_personnel_id'] ?? null);
            $now = $this->clock->now();
            $nextSort = ((int) $visit->serviceLines()->max('sort_order')) + 10;
            if ($nextSort <= 0) $nextSort = 10;

            $sourceType = $this->packages->sourceTypeForNewService($service);
            $sourceReferenceCode = null;
            $packageEnrollmentId = null;
            $serviceCreditId = null;
            $quantity = $this->packages->operationalQuantity($service, (int) ($data['quantity'] ?? 1));

            if (! empty($data['service_credit_id'])) {
                $resolved = $this->packages->resolveCreditForBookingLine(
                    $actor,
                    (int) $visit->customer_id,
                    (int) $data['service_credit_id'],
                    null,
                    $visit->visit_date?->format('Y-m-d'),
                );
                $credit = $resolved['credit'];

                if ((int) $credit->service_id !== (int) $service->id) {
                    throw new DomainException('The selected Service Credit does not match the selected service.');
                }

                $sourceType = 'SERVICE_CREDIT';
                $sourceReferenceCode = $credit->code;
                $packageEnrollmentId = $credit->package_enrollment_id;
                $serviceCreditId = $credit->id;
                $quantity = 1;
            }

            $line = $visit->serviceLines()->create([
                'code' => $visit->code.'-S'.str_pad((string) ($visit->serviceLines()->count() + 1), 2, '0', STR_PAD_LEFT),
                'service_id' => $service->id,
                'service_name_snapshot' => $service->name,
                'source_type' => $sourceType,
                'source_reference_code' => $sourceReferenceCode,
                'package_enrollment_id' => $packageEnrollmentId,
                'service_credit_id' => $serviceCreditId,
                'quantity' => $quantity,
                'assigned_personnel_id' => $personnel?->id,
                'assigned_personnel_name_snapshot' => $this->personnelName($personnel),
                'status' => 'IN_PROGRESS',
                'started_at' => $now,
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'sort_order' => $nextSort,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($sourceType === 'NEW_PACKAGE') {
                $this->packages->ensurePendingEnrollmentForVisitLine($actor, $visit, $line, $service);
                $line->refresh();
            }

            $this->audit->record(
                action: 'ADD_SERVICE',
                module: 'servicing',
                entityType: VisitServiceLine::class,
                entityId: $line->id,
                after: $this->serviceSnapshot($line->fresh()),
                actor: $actor,
                metadata: ['visit_id' => $visit->id, 'visit_code' => $visit->code],
            );

            return $this->loadVisit($visit->fresh());
        });
    }

    public function updateRetailItems(User $actor, Visit $visit, array $items): Visit
    {
        $this->assertOwnedAndOpen($actor, $visit);

        return DB::transaction(function () use ($actor, $visit, $items) {
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndOpen($actor, $visit);

            $before = $this->retailSnapshot($visit);
            $this->syncVisitRetailItems($actor, $visit, $items);
            $after = $this->retailSnapshot($visit->fresh());

            if ($before !== $after) {
                $this->audit->record(
                    action: 'UPDATE_VISIT_RETAIL',
                    module: 'servicing',
                    entityType: Visit::class,
                    entityId: $visit->id,
                    before: ['retail_items' => $before],
                    after: ['retail_items' => $after],
                    actor: $actor,
                    metadata: ['visit_code' => $visit->code, 'source' => 'MANAGE_SERVICING'],
                );
            }

            return $this->loadVisit($visit->fresh());
        });
    }

    public function startService(User $actor, VisitServiceLine $line): Visit
    {
        $this->assertServiceOwned($actor, $line);

        return DB::transaction(function () use ($actor, $line) {
            $line = VisitServiceLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertServiceOwned($actor, $line);
            $visit = Visit::query()->whereKey($line->visit_id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndOpen($actor, $visit);

            if (strtoupper((string) $line->status) !== 'PENDING') {
                throw new DomainException('Only pending services can be started.');
            }

            $before = $this->serviceSnapshot($line);
            $line->update([
                'status' => 'IN_PROGRESS',
                'started_at' => $this->clock->now(),
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'START_SERVICE', module: 'servicing', entityType: VisitServiceLine::class, entityId: $line->id,
                before: $before, after: $this->serviceSnapshot($line->fresh()), actor: $actor,
                metadata: ['visit_id' => $visit->id, 'visit_code' => $visit->code],
            );

            return $this->loadVisit($visit->fresh());
        });
    }

    public function assignStaff(User $actor, VisitServiceLine $line, ?int $personnelId): Visit
    {
        $this->assertServiceOwned($actor, $line);

        return DB::transaction(function () use ($actor, $line, $personnelId) {
            $line = VisitServiceLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertServiceOwned($actor, $line);
            $visit = Visit::query()->whereKey($line->visit_id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndOpen($actor, $visit);

            if ($line->isFinalized()) {
                throw new DomainException('Completed or cancelled service assignments cannot be changed.');
            }

            $personnel = $this->resolvePersonnel($actor, $personnelId);
            $before = $this->serviceSnapshot($line);
            $line->update([
                'assigned_personnel_id' => $personnel?->id,
                'assigned_personnel_name_snapshot' => $this->personnelName($personnel),
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'ASSIGN_STAFF', module: 'servicing', entityType: VisitServiceLine::class, entityId: $line->id,
                before: $before, after: $this->serviceSnapshot($line->fresh()), actor: $actor,
                metadata: ['visit_id' => $visit->id, 'visit_code' => $visit->code],
            );

            return $this->loadVisit($visit->fresh());
        });
    }

    public function completeSelectedServices(User $actor, Visit $visit, array $serviceLineIds, ?array $retailItems = null): Visit
    {
        $this->assertOwned($actor, $visit);

        $ids = collect($serviceLineIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new DomainException('Select at least one service to complete.');
        }

        return DB::transaction(function () use ($actor, $visit, $ids, $retailItems) {
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($actor, $visit);

            if (! $visit->isInService()) {
                throw new DomainException('Only an active Visit can complete services.');
            }

            $lines = VisitServiceLine::query()
                ->where('visit_id', $visit->id)
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lines->count() !== $ids->count()) {
                throw new DomainException('One or more selected services do not belong to this Visit.');
            }

            foreach ($ids as $id) {
                $line = $lines->get($id);
                if (strtoupper((string) $line->status) === 'CANCELLED') {
                    throw new DomainException('Cancelled services cannot be completed.');
                }
            }

            if ($retailItems !== null) {
                $this->updateRetailItems($actor, $visit, $retailItems);
            }

            foreach ($ids as $id) {
                $line = $lines->get($id);
                $this->completeService($actor, $line, []);
            }

            return $this->loadVisit($visit->fresh());
        });
    }

    public function completeService(User $actor, VisitServiceLine $line, array $data = []): Visit
    {
        $this->assertServiceOwned($actor, $line);

        return DB::transaction(function () use ($actor, $line, $data) {
            $line = VisitServiceLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertServiceOwned($actor, $line);
            $visit = Visit::query()->whereKey($line->visit_id)->lockForUpdate()->firstOrFail();

            if (strtoupper((string) $line->status) === 'COMPLETED') {
                return $this->loadVisit($visit);
            }
            if (strtoupper((string) $line->status) === 'CANCELLED') {
                throw new DomainException('Cancelled services cannot be completed.');
            }
            if (! $visit->isInService()) {
                throw new DomainException('Only an active Visit can complete services.');
            }

            $before = $this->serviceSnapshot($line);
            $retailBefore = array_key_exists('retail_items', $data) ? $this->retailSnapshot($visit) : null;

            if (array_key_exists('assigned_personnel_id', $data)) {
                $personnel = $this->resolvePersonnel($actor, $data['assigned_personnel_id'] ?? null);
                $line->update([
                    'assigned_personnel_id' => $personnel?->id,
                    'assigned_personnel_name_snapshot' => $this->personnelName($personnel),
                    'updated_by' => $actor->id,
                ]);
            }

            $this->applyCompletionServiceCredit($actor, $visit, $line, $data);

            if (array_key_exists('retail_items', $data)) {
                $this->syncVisitRetailItems($actor, $visit, $data['retail_items'] ?? []);
            }

            $line->refresh();
            $now = $this->clock->now();
            $line->update([
                'status' => 'COMPLETED',
                'started_at' => $line->started_at ?: $now,
                'completed_at' => $now,
                'updated_by' => $actor->id,
            ]);
            $line->refresh();
            $this->packages->markNewPackageSessionCompleted($actor, $line);
            $this->packages->consumeServiceCredit($actor, $line);

            $event = ServiceCompletionEvent::query()->firstOrCreate(
                ['visit_service_line_id' => $line->id],
                [
                    'business_id' => $actor->business_id,
                    'code' => $this->codes->next(
                        'SERVICE_COMPLETION:'.$now->format('my'),
                        'SCE-'.$now->format('my').'-',
                        6,
                        $actor->business_id,
                    ),
                    'visit_id' => $visit->id,
                    'booking_id' => $visit->booking_id,
                    'customer_id' => $visit->customer_id,
                    'service_id' => $line->service_id,
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'service_name_snapshot' => $line->service_name_snapshot,
                    'service_source' => $line->source_type,
                    'package_enrollment_id' => $line->package_enrollment_id,
                    'service_credit_id' => $line->service_credit_id,
                    'assigned_personnel_name_snapshot' => $line->assigned_personnel_name_snapshot,
                    'quantity' => $line->quantity,
                    'completed_at' => $now,
                    'status' => 'ACTIVE',
                    'created_by' => $actor->id,
                ]
            );

            // Active Service Material Recipes post automatically at the same
            // operational event as service completion. Material shortages do not
            // block completion; inventory may go negative and is offset by later
            // receipts/counts while preserving the consumption movement history.
            $consumption = $this->serviceConsumption->post($actor, $visit, $line, $event);

            if ($retailBefore !== null) {
                $retailAfter = $this->retailSnapshot($visit->fresh());
                if ($retailBefore !== $retailAfter) {
                    $this->audit->record(
                        action: 'UPDATE_VISIT_RETAIL',
                        module: 'servicing',
                        entityType: Visit::class,
                        entityId: $visit->id,
                        before: ['retail_items' => $retailBefore],
                        after: ['retail_items' => $retailAfter],
                        actor: $actor,
                        metadata: ['visit_code' => $visit->code, 'completed_service_line_id' => $line->id],
                    );
                }
            }

            $this->audit->record(
                action: 'COMPLETE_SERVICE', module: 'servicing', entityType: VisitServiceLine::class, entityId: $line->id,
                before: $before, after: $this->serviceSnapshot($line), actor: $actor,
                metadata: [
                    'visit_id' => $visit->id,
                    'visit_code' => $visit->code,
                    'service_completion_event_id' => $event->id,
                    'service_credit_id' => $line->service_credit_id,
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'financial_finalization' => false,
                    'commission_created' => false,
                    'service_consumption_id' => $consumption?->id,
                    'service_consumption_cost' => $consumption ? (float) $consumption->total_cost : 0.0,
                ],
            );

            $this->finalizeOutcome($actor, $visit);

            return $this->loadVisit($visit->fresh());
        });
    }

    public function cancelService(User $actor, VisitServiceLine $line, string $reason): Visit
    {
        $this->assertServiceOwned($actor, $line);

        return DB::transaction(function () use ($actor, $line, $reason) {
            $line = VisitServiceLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertServiceOwned($actor, $line);
            $visit = Visit::query()->whereKey($line->visit_id)->lockForUpdate()->firstOrFail();

            if (strtoupper((string) $line->status) === 'COMPLETED') {
                throw new DomainException('Completed services cannot be cancelled.');
            }
            if (strtoupper((string) $line->status) === 'CANCELLED') {
                $this->finalizeOutcome($actor, $visit, $reason);
                return $this->loadVisit($visit->fresh());
            }
            if (! $visit->isInService()) {
                throw new DomainException('Only an active Visit can cancel services.');
            }

            $before = $this->serviceSnapshot($line);
            $now = $this->clock->now();
            $line->update([
                'status' => 'CANCELLED',
                'cancelled_at' => $now,
                'cancelled_by' => $actor->id,
                'cancellation_reason' => trim($reason),
                'updated_by' => $actor->id,
            ]);
            $this->packages->cancelPendingForVisitLine($actor, $line->fresh(), $reason);

            $this->audit->record(
                action: 'CANCEL_SERVICE', module: 'servicing', entityType: VisitServiceLine::class, entityId: $line->id,
                before: $before, after: $this->serviceSnapshot($line->fresh()), reason: $reason, actor: $actor,
                metadata: ['visit_id' => $visit->id, 'visit_code' => $visit->code],
            );

            $this->finalizeOutcome($actor, $visit, $reason);

            return $this->loadVisit($visit->fresh());
        });
    }

    public function completeVisit(User $actor, Visit $visit): Visit
    {
        $this->assertOwned($actor, $visit);

        return DB::transaction(function () use ($actor, $visit) {
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($actor, $visit);

            if (strtoupper((string) $visit->status) === 'COMPLETED') {
                return $this->loadVisit($visit);
            }
            if (strtoupper((string) $visit->status) === 'CANCELLED') {
                throw new DomainException('Cancelled Visits cannot be completed.');
            }

            $active = $visit->serviceLines()->where('status', '!=', 'CANCELLED')->get();
            $hasRetail = $visit->retailLines()->exists();
            if ($active->isEmpty() && ! $hasRetail) {
                throw new DomainException('The Visit has no active services or retail purchases.');
            }
            if ($active->contains(fn ($line) => strtoupper((string) $line->status) !== 'COMPLETED')) {
                throw new DomainException('Complete all active services before completing the Visit.');
            }

            $this->finalizeOutcome($actor, $visit);
            return $this->loadVisit($visit->fresh());
        });
    }

    public function snapshot(Visit $visit): array
    {
        return [
            'id' => $visit->id,
            'code' => $visit->code,
            'queue_entry_id' => $visit->queue_entry_id,
            'booking_id' => $visit->booking_id,
            'customer_id' => $visit->customer_id,
            'visit_date' => $visit->visit_date?->format('Y-m-d'),
            'status' => $visit->status,
            'check_in_at' => $visit->check_in_at?->toIso8601String(),
            'service_start_at' => $visit->service_start_at?->toIso8601String(),
            'check_out_at' => $visit->check_out_at?->toIso8601String(),
            'services' => $visit->serviceLines->map(fn ($line) => $this->serviceSnapshot($line))->values()->all(),
            'retail_items' => $visit->retailLines->map(fn ($line) => [
                'code' => $line->code,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
            ])->values()->all(),
        ];
    }

    private function finalizeOutcome(User $actor, Visit $visit, ?string $reason = null): string
    {
        $statuses = $visit->serviceLines()->pluck('status')->map(fn ($status) => strtoupper((string) $status));
        $retailCount = $visit->retailLines()->count();
        if ($statuses->isEmpty() && $retailCount === 0) return 'IN_SERVICE';

        if ($statuses->contains(fn ($status) => ! in_array($status, ['COMPLETED', 'CANCELLED'], true))) {
            return 'IN_SERVICE';
        }

        $completedCount = $statuses->filter(fn ($status) => $status === 'COMPLETED')->count();
        $cancelledCount = $statuses->filter(fn ($status) => $status === 'CANCELLED')->count();
        $now = $this->clock->now();

        if ($completedCount === 0 && $cancelledCount > 0 && $retailCount === 0) {
            if (strtoupper((string) $visit->status) !== 'CANCELLED') {
                $visit->update(['status' => 'CANCELLED', 'check_out_at' => $now, 'updated_by' => $actor->id]);
                $visit->booking()->update([
                    'status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $actor->id,
                    'cancellation_reason' => trim((string) ($reason ?: 'All Visit services were cancelled.')), 'updated_by' => $actor->id,
                ]);
                $visit->queueEntry()->update([
                    'status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $actor->id,
                    'cancellation_reason' => trim((string) ($reason ?: 'All Visit services were cancelled.')), 'updated_by' => $actor->id,
                ]);
                $this->audit->record(
                    action: 'CANCEL_VISIT', module: 'servicing', entityType: Visit::class, entityId: $visit->id,
                    after: $this->snapshot($this->loadVisit($visit->fresh())), reason: $reason, actor: $actor,
                    metadata: ['all_services_cancelled' => true],
                );
            }
            return 'CANCELLED';
        }

        if ($completedCount > 0 || $retailCount > 0) {
            $this->assertRetailReadyForFinalization($visit);
            if (strtoupper((string) $visit->status) !== 'COMPLETED') {
                $visit->update(['status' => 'COMPLETED', 'check_out_at' => $now, 'updated_by' => $actor->id]);
                $visit->booking()->update(['status' => 'COMPLETED', 'updated_by' => $actor->id]);
                $visit->queueEntry()->update(['status' => 'COMPLETED', 'updated_by' => $actor->id]);
                $sale = $this->sales->ensureForCompletedVisit($actor, $visit->fresh());
                $this->audit->record(
                    action: 'COMPLETE_VISIT', module: 'servicing', entityType: Visit::class, entityId: $visit->id,
                    after: $this->snapshot($this->loadVisit($visit->fresh())), actor: $actor,
                    metadata: [
                        'completed_service_count' => $completedCount,
                        'cancelled_service_count' => $cancelledCount,
                        'sale_created' => true,
                        'sale_id' => $sale->id,
                        'sale_code' => $sale->code,
                        'commission_created' => false,
                        'inventory_posted' => false,
                    ],
                );
            }
            return 'COMPLETED';
        }

        return 'IN_SERVICE';
    }

    private function applyCompletionServiceCredit(User $actor, Visit $visit, VisitServiceLine $line, array $data): void
    {
        if (! array_key_exists('service_credit_id', $data) || empty($data['service_credit_id'])) {
            return;
        }

        $requestedCreditId = (int) $data['service_credit_id'];
        $currentSource = strtoupper((string) $line->source_type);

        if ($currentSource === 'SERVICE_CREDIT') {
            if ((int) $line->service_credit_id !== $requestedCreditId) {
                throw new DomainException('An existing Service Credit claim cannot be changed during completion.');
            }
            return;
        }

        if ($currentSource !== 'NEW_SERVICE') {
            throw new DomainException('Service Credit can only be applied to a regular service line during completion.');
        }

        if ((int) $line->quantity !== 1) {
            throw new DomainException('Service Credit can only be applied to a single service quantity during completion.');
        }

        $resolved = $this->packages->resolveCreditForBookingLine(
            $actor,
            (int) $visit->customer_id,
            $requestedCreditId,
            null,
            $visit->visit_date?->format('Y-m-d'),
        );
        $credit = $resolved['credit'];

        if ((int) $credit->service_id !== (int) $line->service_id) {
            throw new DomainException('The selected Service Credit does not match the service being completed.');
        }

        $line->update([
            'source_type' => 'SERVICE_CREDIT',
            'source_reference_code' => $credit->code,
            'package_enrollment_id' => $credit->package_enrollment_id,
            'service_credit_id' => $credit->id,
            'quantity' => 1,
            'updated_by' => $actor->id,
        ]);
    }

    private function syncVisitRetailItems(User $actor, Visit $visit, array $items): void
    {
        $this->assertOwnedAndOpen($actor, $visit);

        if ($visit->sale()->exists()) {
            throw new DomainException('Retail purchases can no longer be changed after a Sale has been created.');
        }

        $existing = VisitRetailLine::query()
            ->where('visit_id', $visit->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $submittedIds = [];
        $maxSequence = 0;
        foreach ($existing as $row) {
            if (preg_match('/-R(\d+)$/', (string) $row->code, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        foreach (array_values($items) as $index => $item) {
            $product = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('stock_type', 'INVENTORY')
                ->where('retail_available', true)
                ->find((int) ($item['retail_product_id'] ?? 0));
            if (! $product) {
                throw new DomainException('Select a valid active Retail Product.');
            }

            $personnel = $this->resolveRetailPersonnel($actor, $item['assigned_personnel_id'] ?? null);

            $attributes = [
                'retail_product_id' => $product->id,
                'sku_snapshot' => $product->sku,
                'item_name_snapshot' => $product->name,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price_snapshot' => round(max(0, (float) ($item['unit_price'] ?? $product->sellingPriceForDate() ?? 0)), 2),
                'assigned_personnel_id' => $personnel?->id,
                'assigned_personnel_name_snapshot' => $this->personnelName($personnel),
                'notes' => $this->nullableTrim($item['notes'] ?? null),
                'sort_order' => ($index + 1) * 10,
                'updated_by' => $actor->id,
            ];

            $id = ! empty($item['id']) ? (int) $item['id'] : null;
            if ($id) {
                if (isset($submittedIds[$id])) {
                    throw new DomainException('The same retail line was submitted more than once.');
                }

                /** @var VisitRetailLine|null $row */
                $row = $existing->get($id);
                if (! $row) {
                    throw new DomainException('A retail line does not belong to this active Visit.');
                }

                $row->update($attributes);
                $submittedIds[$id] = true;
                continue;
            }

            $maxSequence++;
            $code = $visit->code.'-R'.str_pad((string) $maxSequence, 2, '0', STR_PAD_LEFT);
            while (VisitRetailLine::query()->where('code', $code)->exists()) {
                $maxSequence++;
                $code = $visit->code.'-R'.str_pad((string) $maxSequence, 2, '0', STR_PAD_LEFT);
            }

            $created = $visit->retailLines()->create(array_merge($attributes, [
                'code' => $code,
                'legacy_retail_item_code' => null,
                'created_by' => $actor->id,
            ]));
            $submittedIds[$created->id] = true;
        }

        foreach ($existing as $id => $row) {
            if (! isset($submittedIds[$id])) {
                $row->delete();
            }
        }

        $visit->unsetRelation('retailLines');
    }

    private function retailSnapshot(Visit $visit): array
    {
        $visit->load('retailLines.assignedPersonnel');

        return $visit->retailLines->map(fn (VisitRetailLine $line) => [
            'id' => $line->id,
            'code' => $line->code,
            'retail_product_id' => $line->retail_product_id,
            'sku' => $line->sku_snapshot,
            'item_name' => $line->item_name_snapshot,
            'quantity' => (int) $line->quantity,
            'unit_price' => (float) $line->unit_price_snapshot,
            'assigned_personnel_id' => $line->assigned_personnel_id,
            'assigned_personnel_name' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
            'notes' => $line->notes,
        ])->values()->all();
    }

    private function resolvePersonnel(User $actor, mixed $personnelId): ?Personnel
    {
        if (! $personnelId) return null;

        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->whereKey((int) $personnelId)
            ->firstOrFail();

        if (strtoupper((string) $personnel->status) !== 'ACTIVE' || ! $personnel->can_perform_services) {
            throw new DomainException('Assigned personnel must be active and allowed to perform services.');
        }

        return $personnel;
    }

    private function resolveRetailPersonnel(User $actor, mixed $personnelId): ?Personnel
    {
        if (! $personnelId) return null;

        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->whereKey((int) $personnelId)
            ->firstOrFail();

        if (strtoupper((string) $personnel->status) !== 'ACTIVE' || ! $personnel->commission_eligible) {
            throw new DomainException('Retail Assigned Staff must be active and commission eligible.');
        }

        return $personnel;
    }

    private function assertRetailReadyForFinalization(Visit $visit): void
    {
        $retailCount = $visit->retailLines()->count();
        if ($retailCount === 0) return;

        $validAssignedCount = $visit->retailLines()
            ->whereNotNull('assigned_personnel_id')
            ->whereHas('assignedPersonnel', function ($query) {
                $query->where('status', 'ACTIVE')->where('commission_eligible', true);
            })
            ->count();

        if ($validAssignedCount !== $retailCount) {
            throw new DomainException('Assign an active commission-eligible staff member to every retail purchase before completing the Visit.');
        }
    }

    private function personnelName(?Personnel $personnel): ?string
    {
        return $personnel ? trim($personnel->first_name.' '.$personnel->last_name) : null;
    }

    private function serviceSnapshot(VisitServiceLine $line): array
    {
        return [
            'id' => $line->id,
            'code' => $line->code,
            'service_id' => $line->service_id,
            'service_name' => $line->service_name_snapshot,
            'source_type' => $line->source_type,
            'source_reference_code' => $line->source_reference_code,
            'package_enrollment_id' => $line->package_enrollment_id,
            'service_credit_id' => $line->service_credit_id,
            'quantity' => $line->quantity,
            'assigned_personnel_id' => $line->assigned_personnel_id,
            'assigned_personnel_name' => $line->assigned_personnel_name_snapshot,
            'status' => $line->status,
            'started_at' => $line->started_at?->toIso8601String(),
            'completed_at' => $line->completed_at?->toIso8601String(),
            'cancelled_at' => $line->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $line->cancellation_reason,
        ];
    }

    private function loadVisit(Visit $visit): Visit
    {
        return $visit->load($this->relations());
    }

    private function relations(): array
    {
        return [
            'customer', 'booking.payments', 'queueEntry', 'branch',
            'serviceLines.service', 'serviceLines.assignedPersonnel', 'serviceLines.completionEvent',
            'retailLines.assignedPersonnel',
        ];
    }

    private function assertOwned(User $actor, Visit $visit): void
    {
        if ($visit->business_id !== $actor->business_id) abort(404);
    }

    private function assertOwnedAndOpen(User $actor, Visit $visit): void
    {
        $this->assertOwned($actor, $visit);
        if (! $visit->isInService()) {
            throw new DomainException('Completed or cancelled Visits can no longer be changed.');
        }
    }

    private function assertServiceOwned(User $actor, VisitServiceLine $line): void
    {
        $businessId = $line->visit()->value('business_id');
        if ((int) $businessId !== (int) $actor->business_id) abort(404);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
