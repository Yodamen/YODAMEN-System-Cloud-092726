<?php

namespace App\Services\Inventory;

use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function create(User $actor, RetailProduct $product, array $data): InventoryAdjustmentRequest
    {
        $this->assertProduct($actor, $product);
        $change = round((float) ($data['quantity_change'] ?? 0), 4);
        $reason = trim((string) ($data['reason'] ?? ''));
        $branchId = (int) ($data['branch_id'] ?? $actor->default_branch_id);

        if (abs($change) < 0.00005) throw new DomainException('Stock adjustment quantity cannot be zero.');
        if ($reason === '') throw new DomainException('Stock adjustment reason is required.');

        $branchExists = DB::table('branches')->where('id', $branchId)->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->exists();
        if (! $branchExists) throw new DomainException('Select a valid active branch.');

        $pendingExists = InventoryAdjustmentRequest::query()
            ->where('business_id', $actor->business_id)
            ->where('branch_id', $branchId)
            ->where('retail_product_id', $product->id)
            ->where('status', 'PENDING')
            ->exists();
        if ($pendingExists) throw new DomainException('This item already has a pending stock adjustment request for the selected branch.');

        $now = now();
        $request = InventoryAdjustmentRequest::query()->create([
            'business_id' => $actor->business_id,
            'code' => $this->codes->next(
                'INVENTORY_ADJUSTMENT_REQUEST:'.$actor->business_id.':'.$now->format('my'),
                'IAR-'.$now->format('my').'-',
                5,
                $actor->business_id,
            ),
            'request_type' => 'ADJUSTMENT',
            'branch_id' => $branchId,
            'retail_product_id' => $product->id,
            'quantity_change' => $change,
            'reason' => $reason,
            'reason_reference_id' => $data['reason_reference_id'] ?? null,
            'status' => 'PENDING',
            'requested_by' => $actor->id,
        ]);

        $this->audit->record(
            action: 'REQUEST_INVENTORY_ADJUSTMENT', module: 'inventory-adjustments', entityType: InventoryAdjustmentRequest::class,
            entityId: $request->id, after: $request->toArray(), reason: $reason, actor: $actor,
        );

        return $this->fresh($request);
    }

    public function requestReversal(User $actor, InventoryAdjustmentRequest $original, string $reason, ?int $reasonReferenceId = null): InventoryAdjustmentRequest
    {
        $this->assertOwned($actor, $original);
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $original, $reason, $reasonReferenceId) {
            $lockedOriginal = InventoryAdjustmentRequest::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($original->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (strtoupper((string) $lockedOriginal->request_type) !== 'ADJUSTMENT') throw new DomainException('Only original manual stock adjustments can be reversed.');
            if (strtoupper((string) $lockedOriginal->status) !== 'APPROVED') throw new DomainException('Only Approved manual stock adjustments can be reversed.');

            $pending = InventoryAdjustmentRequest::query()
                ->where('business_id', $actor->business_id)
                ->where('reversal_of_id', $lockedOriginal->id)
                ->where('status', 'PENDING')
                ->exists();
            if ($pending) throw new DomainException('A reversal request is already pending for this stock adjustment. Approve or reject it before creating another request.');

            $approved = InventoryAdjustmentRequest::query()
                ->where('business_id', $actor->business_id)
                ->where('reversal_of_id', $lockedOriginal->id)
                ->where('status', 'APPROVED')
                ->exists();
            if ($approved) throw new DomainException('This stock adjustment has already been reversed.');

            $now = now();
            $request = InventoryAdjustmentRequest::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next(
                    'INVENTORY_ADJUSTMENT_REVERSAL:'.$actor->business_id.':'.$now->format('my'),
                    'IAR-R-'.$now->format('my').'-',
                    5,
                    $actor->business_id,
                ),
                'request_type' => 'REVERSAL',
                'reversal_of_id' => $lockedOriginal->id,
                'branch_id' => $lockedOriginal->branch_id,
                'retail_product_id' => $lockedOriginal->retail_product_id,
                'quantity_change' => round(-1 * (float) $lockedOriginal->quantity_change, 4),
                'reason' => $reason,
                'reason_reference_id' => $reasonReferenceId,
                'status' => 'PENDING',
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'REQUEST_INVENTORY_ADJUSTMENT_REVERSAL', module: 'inventory-adjustments', entityType: InventoryAdjustmentRequest::class,
                entityId: $request->id, after: $request->toArray(), reason: $reason, actor: $actor,
                metadata: ['reversal_of_id' => $lockedOriginal->id],
            );

            return $this->fresh($request);
        });
    }

    public function approve(User $actor, InventoryAdjustmentRequest $request, ?string $notes = null): InventoryAdjustmentRequest
    {
        $this->assertOwned($actor, $request);

        return DB::transaction(function () use ($actor, $request, $notes) {
            $locked = InventoryAdjustmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING') throw new DomainException('Only pending stock adjustments can be approved.');

            if (strtoupper((string) $locked->request_type) === 'REVERSAL') {
                return $this->approveReversal($actor, $locked, $notes);
            }

            $product = RetailProduct::query()->where('business_id', $actor->business_id)->findOrFail($locked->retail_product_id);
            $this->assertProduct($actor, $product);

            $stock = $this->lockedStock($actor, $locked->branch_id, $product->id);
            $before = round((float) $stock->on_hand, 4);
            $after = round($before + (float) $locked->quantity_change, 4);
            if ($after < 0 && (float) $locked->quantity_change < 0) {
                throw new DomainException('Manual stock deductions cannot create or deepen a negative On Hand balance.');
            }

            $stock->update(['on_hand' => $after]);
            $movement = InventoryMovement::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $locked->branch_id,
                'retail_product_id' => $product->id,
                'movement_type' => 'ADJUSTMENT',
                'quantity_change' => (float) $locked->quantity_change,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost_snapshot' => (float) $product->unit_cost,
                'reference_code' => $locked->code,
                'reason' => $locked->reason,
                'created_by' => $actor->id,
                'movement_at' => now(),
            ]);

            $locked->update([
                'status' => 'APPROVED',
                'inventory_movement_id' => $movement->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->audit->record(
                action: 'APPROVE_INVENTORY_ADJUSTMENT', module: 'inventory-adjustments', entityType: InventoryAdjustmentRequest::class,
                entityId: $locked->id, before: ['status' => 'PENDING', 'on_hand' => $before],
                after: ['status' => 'APPROVED', 'on_hand' => $after], reason: $notes, actor: $actor,
                metadata: ['quantity_change' => (float) $locked->quantity_change, 'retail_product_id' => $product->id, 'movement_id' => $movement->id],
            );

            return $this->fresh($locked);
        });
    }

    private function approveReversal(User $actor, InventoryAdjustmentRequest $locked, ?string $notes): InventoryAdjustmentRequest
    {
        $original = InventoryAdjustmentRequest::query()->whereKey($locked->reversal_of_id)->lockForUpdate()->first();
        if (! $original || (int) $original->business_id !== (int) $actor->business_id) throw new DomainException('Original stock adjustment was not found.');
        if (strtoupper((string) $original->status) !== 'APPROVED') throw new DomainException('The original stock adjustment is no longer eligible for reversal.');

        $sourceMovement = $original->inventory_movement_id
            ? InventoryMovement::query()->whereKey($original->inventory_movement_id)->first()
            : InventoryMovement::query()->where('business_id', $actor->business_id)->where('reference_code', $original->code)->where('movement_type', 'ADJUSTMENT')->latest('id')->first();
        if (! $sourceMovement) throw new DomainException('The original stock movement could not be found for reversal.');

        $product = RetailProduct::query()->where('business_id', $actor->business_id)->findOrFail($locked->retail_product_id);
        $this->assertProduct($actor, $product);
        $stock = $this->lockedStock($actor, $locked->branch_id, $product->id);
        $before = round((float) $stock->on_hand, 4);
        $after = round($before + (float) $locked->quantity_change, 4);
        if ($after < -0.00005) {
            throw new DomainException('The reversal cannot be approved because the current On Hand quantity is no longer enough to undo the original adjustment.');
        }

        $stock->update(['on_hand' => $after]);
        $movement = InventoryMovement::query()->create([
            'business_id' => $actor->business_id,
            'branch_id' => $locked->branch_id,
            'retail_product_id' => $product->id,
            'source_inventory_movement_id' => $sourceMovement->id,
            'movement_type' => 'ADJUSTMENT_REVERSAL',
            'quantity_change' => (float) $locked->quantity_change,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost_snapshot' => (float) $sourceMovement->unit_cost_snapshot,
            'reference_code' => $locked->code,
            'reason' => 'Reverse '.$original->code.'. '.$locked->reason,
            'created_by' => $actor->id,
            'movement_at' => now(),
        ]);

        $locked->update([
            'status' => 'APPROVED',
            'inventory_movement_id' => $movement->id,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
        $original->update(['status' => 'REVERSED']);

        $this->audit->record(
            action: 'APPROVE_INVENTORY_ADJUSTMENT_REVERSAL', module: 'inventory-adjustments', entityType: InventoryAdjustmentRequest::class,
            entityId: $locked->id, before: ['status'=>'PENDING','on_hand'=>$before], after: ['status'=>'APPROVED','on_hand'=>$after],
            reason: $notes ?: $locked->reason, actor: $actor,
            metadata: ['reversal_of_id'=>$original->id,'source_movement_id'=>$sourceMovement->id,'reversal_movement_id'=>$movement->id],
        );

        return $this->fresh($locked);
    }

    public function reject(User $actor, InventoryAdjustmentRequest $request, string $notes, ?int $reviewReasonReferenceId = null): InventoryAdjustmentRequest
    {
        $this->assertOwned($actor, $request);
        $notes = trim($notes);
        if ($notes === '') throw new DomainException('Rejection reason is required.');

        return DB::transaction(function () use ($actor, $request, $notes, $reviewReasonReferenceId) {
            $locked = InventoryAdjustmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING') throw new DomainException('Only pending stock adjustments can be rejected.');

            $locked->update([
                'status' => 'REJECTED',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_reason_reference_id' => $reviewReasonReferenceId,
                'review_notes' => $notes,
            ]);

            $this->audit->record(
                action: strtoupper((string) $locked->request_type) === 'REVERSAL' ? 'REJECT_INVENTORY_ADJUSTMENT_REVERSAL' : 'REJECT_INVENTORY_ADJUSTMENT',
                module: 'inventory-adjustments', entityType: InventoryAdjustmentRequest::class,
                entityId: $locked->id, before: ['status' => 'PENDING'], after: ['status' => 'REJECTED'], reason: $notes, actor: $actor,
            );

            return $this->fresh($locked);
        });
    }

    private function lockedStock(User $actor, int $branchId, int $productId): InventoryStock
    {
        $stock = InventoryStock::query()->firstOrCreate(
            ['branch_id' => $branchId, 'retail_product_id' => $productId],
            ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
        );
        return InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
    }

    private function fresh(InventoryAdjustmentRequest $request): InventoryAdjustmentRequest
    {
        return $request->fresh(['branch', 'product', 'requester', 'reviewer', 'reversalOf', 'movement', 'reasonReference', 'reviewReasonReference']);
    }

    private function assertOwned(User $actor, InventoryAdjustmentRequest $request): void
    {
        if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
    }

    private function assertProduct(User $actor, RetailProduct $product): void
    {
        if ((int) $product->business_id !== (int) $actor->business_id) abort(404);
        if (strtoupper((string) $product->stock_type) !== 'INVENTORY') {
            throw new DomainException('Manual stock adjustments are only available for quantity-tracked Inventory items.');
        }
    }
}
