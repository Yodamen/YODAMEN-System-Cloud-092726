<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use App\Models\RetailProductApprovalRequest;
use App\Models\RetailProductPrice;
use App\Models\User;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetailProductService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function list(User $actor, array $filters = []): Collection
    {
        $branchId = $this->branchId($actor, $filters['branch_id'] ?? null);
        $productId = (int) ($filters['product_id'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $stockType = strtoupper(trim((string) ($filters['stock_type'] ?? '')));
        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        $retail = strtoupper(trim((string) ($filters['retail'] ?? '')));
        $commission = strtoupper(trim((string) ($filters['commission'] ?? '')));

        $query = RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->with([
                'stocks' => fn ($q) => $q->where('branch_id', $branchId),
                'stocks.branch',
                'prices' => fn ($q) => $q->orderBy('effective_from'),
                'approvalRequests' => fn ($q) => $q->where('status', 'PENDING')->latest('id'),
                'approvalRequests.requester',
            ]);

        if ($productId > 0) {
            $query->whereKey($productId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('brand', 'like', '%'.$search.'%');
            });
        }

        if ($category !== '') {
            $query->where('category', $category);
        }
        if (in_array($stockType, ['INVENTORY', 'NON_INVENTORY', 'ASSET'], true)) {
            $query->where('stock_type', $stockType);
        }
        if ($status === 'PENDING_APPROVAL') {
            $query->where(function ($q) {
                $q->where('status', 'PENDING_APPROVAL')
                    ->orWhereHas('approvalRequests', fn ($approval) => $approval->where('status', 'PENDING'));
            });
        } elseif (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status)
                ->whereDoesntHave('approvalRequests', fn ($approval) => $approval->where('status', 'PENDING'));
        }
        if ($retail === 'YES') $query->where('retail_available', true);
        if ($retail === 'NO') $query->where('retail_available', false);
        if ($commission === 'YES') $query->where('commission_eligible', true);
        if ($commission === 'NO') $query->where('commission_eligible', false);

        return $query->orderBy('name')->get()->map(fn (RetailProduct $product) => $this->serialize($product, $branchId));
    }

    public function create(User $actor, array $data): RetailProduct
    {
        return DB::transaction(function () use ($actor, $data) {
            $requestedSku = strtoupper(trim((string) ($data['sku'] ?? '')));

            if ($requestedSku !== '') {
                $this->assertSkuUnique((int) $actor->business_id, $requestedSku);
            }

            $temporarySku = $requestedSku !== ''
                ? $requestedSku
                : 'AUTO-'.strtoupper((string) Str::uuid());

            $stockType = $this->normalizeStockType($data['stock_type'] ?? 'INVENTORY');
            $tracked = $stockType === 'INVENTORY';
            $retailAvailable = $tracked && (bool) ($data['retail_available'] ?? true);
            $basicUom = trim((string) ($data['unit'] ?? 'pc')) ?: 'pc';
            $purchaseUom = trim((string) ($data['purchase_uom'] ?? $basicUom)) ?: $basicUom;
            $purchaseFactor = max(0.000001, (float) ($data['purchase_to_basic_factor'] ?? 1));
            $legacyBasicCost = max(0, (float) ($data['unit_cost'] ?? 0));
            $purchaseUomCost = round(max(0, (float) ($data['purchase_uom_cost'] ?? ($legacyBasicCost * $purchaseFactor))), 4);
            $basicUomCost = round($purchaseUomCost / $purchaseFactor, 4);
            $sellingUom = trim((string) ($data['selling_uom'] ?? $basicUom)) ?: $basicUom;
            $sellingFactor = max(0.000001, (float) ($data['selling_to_basic_factor'] ?? 1));
            $consumptionUom = trim((string) ($data['consumption_uom'] ?? $basicUom)) ?: $basicUom;
            $consumptionFactor = max(0.000001, (float) ($data['consumption_to_basic_factor'] ?? 1));
            $sellingPrice = $retailAvailable ? round((float) ($data['selling_price'] ?? 0), 2) : 0.0;
            $sellingPriceEffectiveFrom = (string) ($data['selling_price_effective_from'] ?? now()->toDateString());
            $requestedStatus = strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
            $requiresApproval = ! $actor->hasPermission('inventory-master', 'APPROVE');

            $product = RetailProduct::query()->create([
                'business_id' => $actor->business_id,
                'sku' => $temporarySku,
                'barcode' => $this->nullable($data['barcode'] ?? null),
                'name' => trim((string) $data['name']),
                'category' => $this->nullable($data['category'] ?? null),
                'brand' => $this->nullable($data['brand'] ?? null),
                'unit' => $basicUom,
                'purchase_uom' => $purchaseUom,
                'purchase_to_basic_factor' => $purchaseFactor,
                'purchase_uom_cost' => $purchaseUomCost,
                'basic_uom_cost' => $basicUomCost,
                'selling_uom' => $sellingUom,
                'selling_to_basic_factor' => $sellingFactor,
                'consumption_uom' => $consumptionUom,
                'consumption_to_basic_factor' => $consumptionFactor,
                'retail_available' => $retailAvailable,
                'stock_type' => $stockType,
                'unit_cost' => round($basicUomCost, 4),
                'selling_price' => $sellingPrice,
                'reorder_point' => $tracked ? round(max(0, (float) ($data['reorder_point'] ?? 0)), 4) : 0,
                'commission_eligible' => $retailAvailable ? (bool) ($data['commission_eligible'] ?? true) : false,
                'status' => $requiresApproval ? 'PENDING_APPROVAL' : $requestedStatus,
                'notes' => $this->nullable($data['notes'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($requestedSku === '') {
                $generatedSku = $this->generatedSku((int) $product->id);
                $this->assertSkuUnique((int) $actor->business_id, $generatedSku, (int) $product->id);
                $product->update(['sku' => $generatedSku]);
                $product->refresh();
            }

            if (! $requiresApproval && $tracked) {
                $this->ensureBranchStocks($actor, $product);
            }
            if (! $requiresApproval && $retailAvailable) {
                $this->createSellingPrice($actor, $product, $sellingPrice, $sellingPriceEffectiveFrom);
            }
            if ($requiresApproval) {
                $this->queueApproval($actor, $product, 'CREATE', $data);
            }

            $this->audit->record(
                action: 'CREATE_RETAIL_PRODUCT', module: 'inventory-master', entityType: RetailProduct::class,
                entityId: $product->id, after: $this->snapshot($product), actor: $actor,
            );

            return $product;
        });
    }

    public function update(User $actor, RetailProduct $product, array $data): RetailProduct
    {
        $this->assertOwned($actor, $product);

        if (! $actor->hasPermission('inventory-master', 'APPROVE')) {
            $this->queueApproval($actor, $product, 'UPDATE', $data);
            $this->audit->record(
                action: 'REQUEST_RETAIL_PRODUCT_CHANGE', module: 'inventory-master', entityType: RetailProduct::class,
                entityId: $product->id, after: ['proposed_data' => $data], actor: $actor,
            );
            return $product->fresh();
        }

        return DB::transaction(function () use ($actor, $product, $data) {
            $product = RetailProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($product);
            $sku = strtoupper(trim((string) ($data['sku'] ?? '')));
            $sku = $sku !== '' ? $sku : strtoupper((string) $product->sku);
            $this->assertSkuUnique((int) $actor->business_id, $sku, (int) $product->id);

            $previousStockType = $this->normalizeStockType($product->stock_type ?: 'INVENTORY');
            $stockType = $this->normalizeStockType($data['stock_type'] ?? $previousStockType);
            $tracked = $stockType === 'INVENTORY';
            $retailAvailable = $tracked && (bool) ($data['retail_available'] ?? $product->retail_available ?? true);
            $basicUom = trim((string) ($data['unit'] ?? $product->unit ?? 'pc')) ?: 'pc';
            $purchaseUom = trim((string) ($data['purchase_uom'] ?? $product->purchase_uom ?? $basicUom)) ?: $basicUom;
            $purchaseFactor = max(0.000001, (float) ($data['purchase_to_basic_factor'] ?? $product->purchase_to_basic_factor ?? 1));
            $legacyBasicCost = array_key_exists('unit_cost', $data) ? max(0, (float) $data['unit_cost']) : (float) ($product->basic_uom_cost ?? $product->unit_cost ?? 0);
            $fallbackPurchaseCost = (float) ($product->purchase_uom_cost ?? ($legacyBasicCost * $purchaseFactor));
            $purchaseUomCost = round(max(0, (float) ($data['purchase_uom_cost'] ?? (array_key_exists('unit_cost', $data) ? $legacyBasicCost * $purchaseFactor : $fallbackPurchaseCost))), 4);
            $basicUomCost = round($purchaseUomCost / $purchaseFactor, 4);
            $sellingUom = trim((string) ($data['selling_uom'] ?? $product->selling_uom ?? $basicUom)) ?: $basicUom;
            $sellingFactor = max(0.000001, (float) ($data['selling_to_basic_factor'] ?? $product->selling_to_basic_factor ?? 1));
            $consumptionUom = trim((string) ($data['consumption_uom'] ?? $product->consumption_uom ?? $basicUom)) ?: $basicUom;
            $consumptionFactor = max(0.000001, (float) ($data['consumption_to_basic_factor'] ?? $product->consumption_to_basic_factor ?? 1));
            $sellingPrice = $retailAvailable ? round((float) ($data['selling_price'] ?? $product->selling_price ?? 0), 2) : 0.0;
            $sellingPriceEffectiveFrom = (string) ($data['selling_price_effective_from'] ?? '');

            if ($previousStockType === 'INVENTORY' && ! $tracked) {
                $hasBalance = InventoryStock::query()
                    ->where('retail_product_id', $product->id)
                    ->where(function ($query) {
                        $query->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0);
                    })
                    ->exists();

                if ($hasBalance) {
                    throw new DomainException('Clear all stock balances before changing an Inventory item to Non-Inventory or Asset.');
                }
            }

            $hasInventoryBalance = InventoryStock::query()
                ->where('retail_product_id', $product->id)
                ->where(function ($query) {
                    $query->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0);
                })
                ->exists();
            $nextWeightedCost = $tracked && $hasInventoryBalance ? (float) $product->unit_cost : round($basicUomCost, 4);

            $product->update([
                'sku' => $sku,
                'barcode' => $this->nullable($data['barcode'] ?? null),
                'name' => trim((string) $data['name']),
                'category' => $this->nullable($data['category'] ?? null),
                'brand' => $this->nullable($data['brand'] ?? null),
                'unit' => $basicUom,
                'purchase_uom' => $purchaseUom,
                'purchase_to_basic_factor' => $purchaseFactor,
                'purchase_uom_cost' => $purchaseUomCost,
                'basic_uom_cost' => $basicUomCost,
                'selling_uom' => $sellingUom,
                'selling_to_basic_factor' => $sellingFactor,
                'consumption_uom' => $consumptionUom,
                'consumption_to_basic_factor' => $consumptionFactor,
                'retail_available' => $retailAvailable,
                'stock_type' => $stockType,
                'unit_cost' => $nextWeightedCost,
                'selling_price' => $retailAvailable ? (float) $product->selling_price : 0,
                'reorder_point' => $tracked ? round(max(0, (float) ($data['reorder_point'] ?? 0)), 4) : 0,
                'commission_eligible' => $retailAvailable ? (bool) ($data['commission_eligible'] ?? true) : false,
                'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
                'notes' => $this->nullable($data['notes'] ?? null),
                'updated_by' => $actor->id,
            ]);

            if ($tracked) {
                $this->ensureBranchStocks($actor, $product);
            }

            if ($retailAvailable) {
                $currentPrice = $this->currentSellingPriceRecord($product, now()->toDateString());
                $currentAmount = $product->sellingPriceForDate();
                $requestedDate = $sellingPriceEffectiveFrom !== '' ? $sellingPriceEffectiveFrom : ($currentPrice?->effective_from?->toDateString() ?? now()->toDateString());
                $priceChanged = abs($sellingPrice - $currentAmount) > 0.0001;
                $dateChanged = $currentPrice && $requestedDate !== $currentPrice->effective_from?->toDateString();
                if ($priceChanged || $dateChanged || ! $currentPrice) {
                    $this->createSellingPrice($actor, $product, $sellingPrice, $requestedDate);
                }
            }

            $this->audit->record(
                action: 'EDIT_RETAIL_PRODUCT', module: 'inventory-master', entityType: RetailProduct::class,
                entityId: $product->id, before: $before, after: $this->snapshot($product->fresh()), actor: $actor,
            );

            return $product->fresh();
        });
    }

    public function toggle(User $actor, RetailProduct $product): RetailProduct
    {
        $this->assertOwned($actor, $product);
        $currentStatus = strtoupper((string) $product->status);
        if ($currentStatus === 'PENDING_APPROVAL') {
            throw new DomainException('Pending inventory items must be approved before their status can be changed.');
        }
        if ($currentStatus === 'ACTIVE') {
            $hasBalance = InventoryStock::query()
                ->where('retail_product_id', $product->id)
                ->where(function ($query) {
                    $query->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0);
                })
                ->exists();
            if ($hasBalance) {
                throw new DomainException('This item cannot be deactivated while stock remains on hand or reserved.');
            }
        }

        $before = $this->snapshot($product);
        $product->update([
            'status' => $currentStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE',
            'updated_by' => $actor->id,
        ]);

        $this->audit->record(
            action: strtoupper((string) $product->status) === 'ACTIVE' ? 'ACTIVATE_RETAIL_PRODUCT' : 'DEACTIVATE_RETAIL_PRODUCT',
            module: 'inventory-master', entityType: RetailProduct::class, entityId: $product->id,
            before: $before, after: $this->snapshot($product->fresh()), actor: $actor,
        );

        return $product->fresh();
    }

    public function approve(User $actor, RetailProduct $product): RetailProduct
    {
        $this->assertOwned($actor, $product);
        if (! $actor->hasPermission('inventory-master', 'APPROVE')) {
            throw new DomainException('You do not have permission to approve inventory items.');
        }

        return DB::transaction(function () use ($actor, $product) {
            $approval = RetailProductApprovalRequest::query()
                ->where('business_id', $actor->business_id)
                ->where('retail_product_id', $product->id)
                ->where('status', 'PENDING')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $approval) {
                throw new DomainException('This inventory item has no pending approval request.');
            }

            $updated = $this->update($actor, $product, (array) $approval->proposed_data);
            $approval->update([
                'status' => 'APPROVED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->audit->record(
                action: 'APPROVE_RETAIL_PRODUCT', module: 'inventory-master', entityType: RetailProduct::class,
                entityId: $product->id, before: ['approval_request_id' => $approval->id, 'status' => 'PENDING'],
                after: ['approval_request_id' => $approval->id, 'status' => 'APPROVED'], actor: $actor,
            );

            return $updated->fresh();
        });
    }

    public function adjustStock(User $actor, RetailProduct $product, array $data): InventoryStock
    {
        $this->assertOwned($actor, $product);
        $stockType = $this->normalizeStockType($product->stock_type ?: 'INVENTORY');
        if ($stockType === 'NON_INVENTORY') {
            throw new DomainException('Non-inventory items do not use stock adjustments or quantity balances.');
        }
        if ($stockType === 'ASSET') {
            throw new DomainException('Asset items do not use stock adjustments or quantity balances.');
        }

        $branchId = $this->branchId($actor, $data['branch_id'] ?? null);
        $change = round((float) ($data['quantity_change'] ?? 0), 4);
        $reason = trim((string) ($data['reason'] ?? ''));
        if (abs($change) < 0.00005) {
            throw new DomainException('Stock adjustment quantity cannot be zero.');
        }
        if ($reason === '') {
            throw new DomainException('Stock adjustment reason is required.');
        }

        return DB::transaction(function () use ($actor, $product, $branchId, $change, $reason) {
            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $branchId, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $before = round((float) $stock->on_hand, 4);
            $after = round($before + $change, 4);
            if ($after < 0) {
                throw new DomainException('Stock adjustment cannot reduce On Hand below zero.');
            }

            $stock->update(['on_hand' => $after]);
            InventoryMovement::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $branchId,
                'retail_product_id' => $product->id,
                'movement_type' => 'ADJUSTMENT',
                'quantity_change' => $change,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost_snapshot' => (float) $product->unit_cost,
                'reference_code' => 'ADJ-'.$stock->id.'-'.now()->format('YmdHis'),
                'reason' => $reason,
                'created_by' => $actor->id,
                'movement_at' => now(),
            ]);

            $this->audit->record(
                action: 'ADJUST_RETAIL_STOCK', module: 'inventory-adjustments', entityType: InventoryStock::class,
                entityId: $stock->id, before: ['on_hand' => $before], after: ['on_hand' => $after], actor: $actor,
                metadata: ['retail_product_id' => $product->id, 'branch_id' => $branchId, 'quantity_change' => $change, 'reason' => $reason],
            );

            return $stock->fresh();
        });
    }

    public function serialize(RetailProduct $product, int $branchId): array
    {
        $product->loadMissing(['stocks' => fn ($q) => $q->where('branch_id', $branchId)]);
        $stock = $product->stocks->first();
        $stockType = $this->normalizeStockType($product->stock_type ?: 'INVENTORY');
        $tracked = $stockType === 'INVENTORY';
        $onHand = $tracked ? (float) ($stock?->on_hand ?? 0) : 0;
        $reorderPoint = $tracked ? (float) $product->reorder_point : 0;
        $retailAvailable = $tracked && (bool) $product->retail_available;
        $commissionEligible = $retailAvailable ? (bool) $product->commission_eligible : false;
        // Legacy column name retained for compatibility. Semantics are:
        // 1 Basic UOM = N Consumption UOM.
        $consumptionFactor = max(0.000001, (float) ($product->consumption_to_basic_factor ?: 1));
        $basicUomCost = (float) ($product->basic_uom_cost ?? $product->unit_cost ?? 0);
        $weightedBasicCost = (float) ($product->unit_cost ?? 0);
        $consumptionUomCost = round($basicUomCost / $consumptionFactor, 4);
        $weightedConsumptionCost = round($weightedBasicCost / $consumptionFactor, 4);
        $currentPrice = $retailAvailable ? $this->currentSellingPriceRecord($product, now()->toDateString()) : null;
        $currentSellingPrice = $retailAvailable ? $product->sellingPriceForDate() : 0.0;
        $futurePrice = $retailAvailable ? $this->futureSellingPriceRecord($product, now()->toDateString()) : null;
        $pendingApproval = $product->relationLoaded('approvalRequests')
            ? $product->approvalRequests->firstWhere('status', 'PENDING')
            : RetailProductApprovalRequest::query()->where('retail_product_id', $product->id)->where('status', 'PENDING')->latest('id')->with('requester')->first();
        $hasStockBalance = InventoryStock::query()
            ->where('retail_product_id', $product->id)
            ->where(function ($query) { $query->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0); })
            ->exists();
        $displayStatus = $pendingApproval ? 'PENDING_APPROVAL' : strtoupper((string) $product->status);

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'category' => $product->category,
            'brand' => $product->brand,
            'unit' => $product->unit,
            'basic_uom' => $product->unit,
            'purchase_uom' => $product->purchase_uom ?: $product->unit,
            'purchase_to_basic_factor' => (float) ($product->purchase_to_basic_factor ?: 1),
            'purchase_uom_cost' => (float) ($product->purchase_uom_cost ?? ((float) $product->unit_cost * (float) ($product->purchase_to_basic_factor ?: 1))),
            'basic_uom_cost' => (float) ($product->basic_uom_cost ?? $product->unit_cost),
            'selling_uom' => $product->selling_uom ?: $product->unit,
            'selling_to_basic_factor' => (float) ($product->selling_to_basic_factor ?: 1),
            'consumption_uom' => $product->consumption_uom ?: $product->unit,
            'consumption_to_basic_factor' => $consumptionFactor,
            'basic_to_consumption_factor' => $consumptionFactor,
            'consumption_uom_cost' => $consumptionUomCost,
            'retail_available' => $retailAvailable,
            'stock_type' => $stockType,
            'unit_cost' => $weightedBasicCost,
            'weighted_average_cost' => $weightedBasicCost,
            'weighted_average_consumption_cost' => $weightedConsumptionCost,
            'selling_price' => $currentSellingPrice,
            'selling_price_effective_from' => $currentPrice?->effective_from?->toDateString(),
            'future_selling_price' => $futurePrice ? [
                'amount' => (float) $futurePrice->selling_price,
                'effective_from' => $futurePrice->effective_from?->toDateString(),
            ] : null,
            'selling_price_history' => $product->prices
                ->sortByDesc(fn (RetailProductPrice $price) => $price->effective_from?->toDateString())
                ->map(fn (RetailProductPrice $price) => [
                    'id' => $price->id,
                    'amount' => (float) $price->selling_price,
                    'effective_from' => $price->effective_from?->toDateString(),
                    'effective_until' => $price->effective_until?->toDateString(),
                    'status' => $price->status,
                ])->values(),
            'reorder_point' => $reorderPoint,
            'commission_eligible' => $commissionEligible,
            'status' => $product->status,
            'display_status' => $displayStatus,
            'approval_pending' => (bool) $pendingApproval,
            'approval_request' => $pendingApproval ? [
                'id' => $pendingApproval->id,
                'request_type' => $pendingApproval->request_type,
                'requested_by' => $pendingApproval->requester?->display_name,
                'requested_at' => $pendingApproval->created_at?->toIso8601String(),
            ] : null,
            'requested_status' => $pendingApproval ? strtoupper((string) (($pendingApproval->proposed_data['status'] ?? null) ?: 'ACTIVE')) : $product->status,
            'has_stock_balance' => $hasStockBalance,
            'notes' => $product->notes,
            'branch_id' => $branchId,
            'on_hand' => $onHand,
            'reserved' => $tracked ? (float) ($stock?->reserved ?? 0) : 0,
            'available' => $tracked ? max(0, round($onHand - (float) ($stock?->reserved ?? 0), 4)) : 0,
            'low_stock' => $tracked && $onHand <= $reorderPoint,
        ];
    }

    private function queueApproval(User $actor, RetailProduct $product, string $requestType, array $data): RetailProductApprovalRequest
    {
        $existing = RetailProductApprovalRequest::query()
            ->where('business_id', $actor->business_id)
            ->where('retail_product_id', $product->id)
            ->where('status', 'PENDING')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'request_type' => strtoupper($requestType),
                'proposed_data' => $data,
                'requested_by' => $actor->id,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            return $existing->fresh();
        }

        return RetailProductApprovalRequest::query()->create([
            'business_id' => $actor->business_id,
            'retail_product_id' => $product->id,
            'request_type' => strtoupper($requestType),
            'proposed_data' => $data,
            'status' => 'PENDING',
            'requested_by' => $actor->id,
        ]);
    }

    private function branchId(User $actor, mixed $branchId): int
    {
        $id = (int) ($branchId ?: $actor->default_branch_id);
        $branch = Branch::query()->where('business_id', $actor->business_id)->whereKey($id)->first();
        if (! $branch) {
            throw new DomainException('Select a valid branch.');
        }

        return (int) $branch->id;
    }

    private function assertOwned(User $actor, RetailProduct $product): void
    {
        if ((int) $product->business_id !== (int) $actor->business_id) {
            abort(404);
        }
    }

    private function generatedSku(int $productId): string
    {
        return 'SKU-'.str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
    }

    private function assertSkuUnique(int $businessId, string $sku, ?int $exceptId = null): void
    {
        if ($sku === '') {
            throw new DomainException('SKU is required.');
        }
        $query = RetailProduct::query()->where('business_id', $businessId)->where('sku', strtoupper($sku));
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw new DomainException('SKU is already in use.');
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeStockType(mixed $value): string
    {
        $stockType = strtoupper(trim((string) $value));

        return in_array($stockType, ['INVENTORY', 'NON_INVENTORY', 'ASSET'], true)
            ? $stockType
            : 'INVENTORY';
    }

    private function ensureBranchStocks(User $actor, RetailProduct $product): void
    {
        Branch::query()
            ->where('business_id', $actor->business_id)
            ->get(['id'])
            ->each(fn (Branch $branch) => InventoryStock::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            ));
    }

    private function snapshot(RetailProduct $product): array
    {
        $stockType = $this->normalizeStockType($product->stock_type ?: 'INVENTORY');
        $tracked = $stockType === 'INVENTORY';
        $retailAvailable = $tracked && (bool) $product->retail_available;
        $currentPrice = $retailAvailable ? $this->currentSellingPriceRecord($product, now()->toDateString()) : null;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'brand' => $product->brand,
            'stock_type' => $stockType,
            'basic_uom' => $product->unit,
            'purchase_uom' => $product->purchase_uom ?: $product->unit,
            'purchase_to_basic_factor' => (float) ($product->purchase_to_basic_factor ?: 1),
            'purchase_uom_cost' => (float) ($product->purchase_uom_cost ?? 0),
            'basic_uom_cost' => (float) ($product->basic_uom_cost ?? $product->unit_cost),
            'selling_uom' => $product->selling_uom ?: $product->unit,
            'selling_to_basic_factor' => (float) ($product->selling_to_basic_factor ?: 1),
            'consumption_uom' => $product->consumption_uom ?: $product->unit,
            'consumption_to_basic_factor' => (float) ($product->consumption_to_basic_factor ?: 1),
            'basic_to_consumption_factor' => (float) ($product->consumption_to_basic_factor ?: 1),
            'consumption_uom_cost' => round(
                (float) ($product->basic_uom_cost ?? $product->unit_cost ?? 0)
                / max(0.000001, (float) ($product->consumption_to_basic_factor ?: 1)),
                4
            ),
            'retail_available' => $retailAvailable,
            'unit_cost' => (float) $product->unit_cost,
            'weighted_average_consumption_cost' => round(
                (float) ($product->unit_cost ?? 0)
                / max(0.000001, (float) ($product->consumption_to_basic_factor ?: 1)),
                4
            ),
            'selling_price' => $retailAvailable ? $product->sellingPriceForDate() : 0,
            'selling_price_effective_from' => $currentPrice?->effective_from?->toDateString(),
            'reorder_point' => $tracked ? (float) $product->reorder_point : 0,
            'commission_eligible' => $retailAvailable ? (bool) $product->commission_eligible : false,
            'status' => $product->status,
        ];
    }

    private function createSellingPrice(User $actor, RetailProduct $product, float $amount, string $effectiveFrom): RetailProductPrice
    {
        $effectiveFrom = CarbonImmutable::parse($effectiveFrom)->toDateString();
        $existing = RetailProductPrice::query()
            ->where('retail_product_id', $product->id)
            ->whereDate('effective_from', $effectiveFrom)
            ->first();

        if ($existing) {
            if (abs((float) $existing->selling_price - $amount) < 0.0001) return $existing;
            throw new DomainException('A retail selling price already exists for this effectivity date. Choose a different date.');
        }

        $price = RetailProductPrice::query()->create([
            'business_id' => $actor->business_id,
            'retail_product_id' => $product->id,
            'selling_price' => round($amount, 2),
            'effective_from' => $effectiveFrom,
            'status' => 'SCHEDULED',
            'created_by' => $actor->id,
        ]);

        $this->rebuildSellingPricePeriods($product);
        return $price->fresh();
    }

    private function rebuildSellingPricePeriods(RetailProduct $product): void
    {
        $rows = RetailProductPrice::query()
            ->where('retail_product_id', $product->id)
            ->orderBy('effective_from')
            ->lockForUpdate()
            ->get();
        $today = CarbonImmutable::parse(now()->toDateString());
        $currentAmount = null;

        foreach ($rows as $index => $row) {
            $next = $rows->get($index + 1);
            $until = $next?->effective_from?->subDay();
            $from = CarbonImmutable::parse($row->effective_from);
            $status = 'ACTIVE';
            if ($from->isAfter($today)) $status = 'SCHEDULED';
            elseif ($until && CarbonImmutable::parse($until)->isBefore($today)) $status = 'SUPERSEDED';
            else $currentAmount = (float) $row->selling_price;

            $row->forceFill([
                'effective_until' => $until?->toDateString(),
                'status' => $status,
            ])->save();
        }

        if ($currentAmount !== null) {
            $product->forceFill(['selling_price' => round($currentAmount, 2)])->save();
        }
    }

    private function currentSellingPriceRecord(RetailProduct $product, string $date): ?RetailProductPrice
    {
        if ($product->relationLoaded('prices')) {
            return $product->prices
                ->filter(fn (RetailProductPrice $price) => $price->effective_from?->toDateString() <= $date)
                ->filter(fn (RetailProductPrice $price) => ! $price->effective_until || $price->effective_until?->toDateString() >= $date)
                ->sortByDesc(fn (RetailProductPrice $price) => $price->effective_from?->toDateString())
                ->first();
        }

        return RetailProductPrice::query()
            ->where('retail_product_id', $product->id)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function futureSellingPriceRecord(RetailProduct $product, string $date): ?RetailProductPrice
    {
        if ($product->relationLoaded('prices')) {
            return $product->prices
                ->filter(fn (RetailProductPrice $price) => $price->effective_from?->toDateString() > $date)
                ->sortBy(fn (RetailProductPrice $price) => $price->effective_from?->toDateString())
                ->first();
        }

        return RetailProductPrice::query()
            ->where('retail_product_id', $product->id)
            ->whereDate('effective_from', '>', $date)
            ->orderBy('effective_from')
            ->first();
    }
}
