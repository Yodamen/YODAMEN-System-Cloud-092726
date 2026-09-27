<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryOperationsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->branchId($request);
        $productId = $request->integer('product_id');
        $category = trim((string) $request->query('category', ''));
        $status = strtoupper(trim((string) $request->query('status', 'ALL')));
        $sellable = strtoupper(trim((string) $request->query('sellable', 'ALL')));

        $allProducts = RetailProduct::query()
            ->where('business_id', $user->business_id)
            ->where('stock_type', 'INVENTORY')
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'category', 'status']);

        $query = RetailProduct::query()
            ->where('business_id', $user->business_id)
            ->where('stock_type', 'INVENTORY')
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->with(['stocks' => fn ($q) => $q->where('branch_id', $branchId)]);

        if ($productId > 0) $query->whereKey($productId);
        if ($category !== '') $query->where('category', $category);
        if ($sellable === 'YES') $query->where('retail_available', true);
        if ($sellable === 'NO') $query->where('retail_available', false);

        $products = $query->orderBy('name')->get();
        $rows = $products->map(function (RetailProduct $product) {
            $stock = $product->stocks->first();
            $onHand = round((float) ($stock?->on_hand ?? 0), 4);
            $reserved = round((float) ($stock?->reserved ?? 0), 4);
            $available = round($onHand - $reserved, 4);
            $isActive = strtoupper((string) $product->status) === 'ACTIVE';
            $negativeStock = $isActive && $onHand < 0;
            $outOfStock = $isActive && $available <= 0;
            $lowStock = $isActive && ! $outOfStock && $available <= (float) ($product->reorder_point ?? 0);
            $weightedCost = (float) ($product->unit_cost ?? 0);
            $sellable = (bool) ($product->retail_available ?? false);
            $sellingPrice = $sellable ? $product->sellingPriceForDate() : 0.0;
            $stockStatus = ! $isActive
                ? 'INACTIVE'
                : ($negativeStock ? 'NEGATIVE STOCK' : ($outOfStock ? 'OUT OF STOCK' : ($lowStock ? 'LOW STOCK' : 'OK')));

            return [
                'id' => $stock?->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
                'unit' => $product->unit,
                'product_status' => strtoupper((string) $product->status),
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'negative_stock' => $negativeStock,
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
                'status' => $stockStatus,
                'sellable' => $sellable,
                'retail_available' => $sellable,
                'weighted_average_cost' => $weightedCost,
                'unit_cost' => $weightedCost,
                'selling_price' => $sellingPrice,
                'cost_value' => round($onHand * $weightedCost, 2),
                'retail_value' => $isActive && $sellable ? round(max(0, $available) * $sellingPrice, 2) : 0.0,
            ];
        })->filter(function (array $row) use ($status) {
            return match ($status) {
                'NEGATIVE_STOCK' => $row['negative_stock'] === true,
                'OUT_OF_STOCK' => $row['out_of_stock'] === true,
                'LOW_STOCK' => $row['low_stock'] === true,
                'OK' => $row['status'] === 'OK',
                'INACTIVE' => $row['product_status'] === 'INACTIVE',
                default => true,
            };
        })->values();

        return response()->json([
            'branch_id' => $branchId,
            'branches' => $this->branches($request),
            'reference_options' => [
                'categories' => $allProducts->pluck('category')->filter()->unique()->sort()->values(),
                'statuses' => [
                    ['code' => 'ALL', 'value' => 'All Statuses'],
                    ['code' => 'OK', 'value' => 'OK'],
                    ['code' => 'LOW_STOCK', 'value' => 'Low Stock'],
                    ['code' => 'OUT_OF_STOCK', 'value' => 'Out of Stock'],
                    ['code' => 'NEGATIVE_STOCK', 'value' => 'Negative Stock'],
                    ['code' => 'INACTIVE', 'value' => 'Inactive'],
                ],
                'sellable' => [
                    ['code' => 'ALL', 'value' => 'All'],
                    ['code' => 'YES', 'value' => 'Sellable'],
                    ['code' => 'NO', 'value' => 'Not Sellable'],
                ],
                'products' => $allProducts->map(fn (RetailProduct $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'label' => $product->name,
                ])->values(),
            ],
            'summary' => [
                'active_skus' => $rows->where('product_status', 'ACTIVE')->count(),
                'inactive_skus' => $rows->where('product_status', 'INACTIVE')->count(),
                'out_of_stock_skus' => $rows->where('out_of_stock', true)->count(),
                'negative_stock_skus' => $rows->where('negative_stock', true)->count(),
                'low_stock_skus' => $rows->where('low_stock', true)->count(),
                'on_hand_units' => $rows->sum('on_hand'),
                'available_units' => $rows->sum('available'),
                'cost_value' => round((float) $rows->sum('cost_value'), 2),
                'retail_value' => round((float) $rows->sum('retail_value'), 2),
            ],
            'rows' => $rows,
        ]);
    }

    public function productMovements(Request $request, RetailProduct $retailProduct): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $retailProduct->business_id === (int) $user->business_id && $retailProduct->stock_type === 'INVENTORY', 404);

        $branchId = $this->branchId($request);
        $stock = InventoryStock::query()
            ->where('business_id', $user->business_id)
            ->where('branch_id', $branchId)
            ->where('retail_product_id', $retailProduct->id)
            ->first();

        $query = InventoryMovement::query()
            ->where('business_id', $user->business_id)
            ->where('branch_id', $branchId)
            ->where('retail_product_id', $retailProduct->id)
            ->with(['product', 'branch', 'sale'])
            ->orderByDesc('movement_at')
            ->orderByDesc('id');

        if ($request->filled('from')) $query->whereDate('movement_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->whereDate('movement_at', '<=', $request->query('to'));
        if ($request->filled('movement_type') && strtoupper((string) $request->query('movement_type')) !== 'ALL') {
            $query->where('movement_type', strtoupper((string) $request->query('movement_type')));
        }

        $movementTypes = InventoryMovement::query()
            ->where('business_id', $user->business_id)
            ->where('branch_id', $branchId)
            ->where('retail_product_id', $retailProduct->id)
            ->distinct()
            ->orderBy('movement_type')
            ->pluck('movement_type')
            ->values();

        $onHand = round((float) ($stock?->on_hand ?? 0), 4);
        $reserved = round((float) ($stock?->reserved ?? 0), 4);
        $available = round($onHand - $reserved, 4);
        $weightedCost = (float) ($retailProduct->unit_cost ?? 0);
        $sellingPrice = $retailProduct->retail_available ? $retailProduct->sellingPriceForDate() : 0.0;

        return response()->json([
            'branch_id' => $branchId,
            'product' => [
                'id' => $retailProduct->id,
                'sku' => $retailProduct->sku,
                'name' => $retailProduct->name,
                'category' => $retailProduct->category,
                'unit' => $retailProduct->unit,
                'sellable' => (bool) $retailProduct->retail_available,
                'selling_price' => $sellingPrice,
                'weighted_average_cost' => $weightedCost,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'cost_value' => round($onHand * $weightedCost, 2),
                'retail_value' => $retailProduct->retail_available ? round(max(0, $available) * $sellingPrice, 2) : 0.0,
            ],
            'movement_types' => $movementTypes,
            'rows' => $query->limit(500)->get()->map(fn (InventoryMovement $row) => $this->movementRow($row))->values(),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $query = $this->movementQuery($request);
        return response()->json([
            'branches' => $this->branches($request),
            'rows' => $query->limit(500)->get()->map(fn (InventoryMovement $row) => $this->movementRow($row))->values(),
        ]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $request->merge(['movement_type' => 'ALL']);
        $query = $this->movementQuery($request)->whereIn('movement_type', ['ADJUSTMENT', 'ADJUSTMENT_REVERSAL']);
        $products = RetailProduct::query()
            ->where('business_id', $request->user()->business_id)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'unit'])
            ->map(fn (RetailProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit,
                'label' => $product->name,
            ])->values();

        $requests = InventoryAdjustmentRequest::query()
            ->where('business_id', $request->user()->business_id)
            ->with(['product', 'branch', 'requester', 'reviewer', 'reversalOf'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn (InventoryAdjustmentRequest $row) => [
                'id' => $row->id,
                'code' => $row->code,
                'request_type' => strtoupper((string) ($row->request_type ?: 'ADJUSTMENT')),
                'reversal_of_id' => $row->reversal_of_id,
                'status' => $row->status,
                'quantity_change' => (float) $row->quantity_change,
                'reason' => $row->reason,
                'review_notes' => $row->review_notes,
                'created_at' => $row->created_at?->toIso8601String(),
                'reviewed_at' => $row->reviewed_at?->toIso8601String(),
                'product' => ['id' => $row->product?->id, 'sku' => $row->product?->sku, 'name' => $row->product?->name, 'unit' => $row->product?->unit],
                'branch' => ['id' => $row->branch?->id, 'name' => $row->branch?->name],
                'requested_by' => $row->requester?->display_name,
                'reviewed_by' => $row->reviewer?->display_name,
            ])->values();

        return response()->json([
            'branches' => $this->branches($request),
            'products' => $products,
            'requests' => $requests,
            'rows' => $query->limit(500)->get()->map(fn (InventoryMovement $row) => $this->movementRow($row))->values(),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->branchId($request);
        $search = trim((string) $request->query('search', ''));

        $query = InventoryStock::query()
            ->where('business_id', $user->business_id)
            ->where('branch_id', $branchId)
            ->with(['product', 'branch'])
            ->whereHas('product', fn ($q) => $q->where('status', 'ACTIVE')->where('stock_type', 'INVENTORY'));

        if ($search !== '') {
            $query->whereHas('product', fn ($q) => $q->where('sku', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
        }

        $rows = $query->get()->map(function (InventoryStock $stock) {
            $product = $stock->product;
            $available = round((float) $stock->on_hand - (float) $stock->reserved, 4);
            $reorder = (float) ($product?->reorder_point ?? 0);
            return [
                'product_id' => $product?->id,
                'sku' => $product?->sku,
                'name' => $product?->name,
                'category' => $product?->category,
                'unit' => $product?->unit,
                'available' => $available,
                'reorder_point' => $reorder,
                'shortfall_to_reorder_point' => max(0, $reorder - $available),
                'unit_cost' => (float) ($product?->unit_cost ?? 0),
            ];
        })->filter(fn (array $row) => $row['available'] <= $row['reorder_point'])
          ->sortBy(fn (array $row) => [$row['available'] - $row['reorder_point'], strtolower((string) $row['name'])])
          ->values();

        return response()->json(['branch_id' => $branchId, 'branches' => $this->branches($request), 'rows' => $rows]);
    }

    private function movementQuery(Request $request)
    {
        $user = $request->user();
        $query = InventoryMovement::query()
            ->where('business_id', $user->business_id)
            ->with(['product', 'branch', 'sale'])
            ->orderByDesc('movement_at')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) $query->where('branch_id', $request->integer('branch_id'));
        elseif ($user->default_branch_id) $query->where('branch_id', $user->default_branch_id);
        if ($request->filled('from')) $query->whereDate('movement_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->whereDate('movement_at', '<=', $request->query('to'));
        if ($request->filled('movement_type')) {
            $movementType = strtoupper(trim((string) $request->query('movement_type')));
            $types = collect(explode(',', $movementType))->map(fn ($type) => trim((string) $type))->filter()->values();
            if ($types->count() > 1) $query->whereIn('movement_type', $types->all());
            elseif ($types->isNotEmpty()) $query->where('movement_type', $types->first());
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $query->where(function ($q) use ($term) {
                $q->where('reference_code', 'like', '%'.$term.'%')
                    ->orWhere('reason', 'like', '%'.$term.'%')
                    ->orWhereHas('product', fn ($pq) => $pq->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'));
            });
        }
        return $query;
    }

    private function movementRow(InventoryMovement $row): array
    {
        return [
            'id' => $row->id,
            'movement_at' => $row->movement_at?->toIso8601String(),
            'movement_type' => $row->movement_type,
            'quantity_change' => (float) $row->quantity_change,
            'quantity_before' => (float) $row->quantity_before,
            'quantity_after' => (float) $row->quantity_after,
            'unit_cost_snapshot' => (float) $row->unit_cost_snapshot,
            'reference_code' => $row->reference_code,
            'reason' => $row->reason,
            'product' => ['id' => $row->product?->id, 'sku' => $row->product?->sku, 'name' => $row->product?->name, 'unit' => $row->product?->unit],
            'branch' => ['id' => $row->branch?->id, 'code' => $row->branch?->code, 'name' => $row->branch?->name],
            'sale_code' => $row->sale?->code,
        ];
    }

    private function branches(Request $request)
    {
        return Branch::query()->where('business_id', $request->user()->business_id)->where('status', 'ACTIVE')
            ->orderBy('name')->get(['id', 'code', 'name'])->values();
    }

    private function branchId(Request $request): int
    {
        $id = $request->integer('branch_id') ?: (int) $request->user()->default_branch_id;
        $query = Branch::query()->where('business_id', $request->user()->business_id)->where('status', 'ACTIVE');
        $branch = $id ? (clone $query)->whereKey($id)->first() : $query->orderBy('name')->first();
        abort_unless($branch, 422, 'Select a valid branch.');
        return (int) $branch->id;
    }
}
