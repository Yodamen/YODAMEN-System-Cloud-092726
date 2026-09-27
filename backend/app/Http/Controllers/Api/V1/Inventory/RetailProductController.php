<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Inventory\AdjustRetailStockRequest;
use App\Http\Requests\Api\V1\Inventory\SaveRetailProductRequest;
use App\Models\Branch;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Services\Inventory\RetailProductService;
use App\Services\Inventory\InventoryAdjustmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RetailProductController extends Controller
{
    public function index(Request $request, RetailProductService $service): JsonResponse
    {
        $user = $request->user();
        $businessId = (int) $user->business_id;
        $branchId = $request->integer('branch_id') ?: $user->default_branch_id;

        $inventoryCategories = $this->referenceOptions($businessId, 'PRODUCT_CATEGORY');
        $nonInventoryCategories = $this->referenceOptions($businessId, 'NON_INVENTORY_CATEGORY');
        $assetCategories = $this->referenceOptions($businessId, 'ASSET_CATEGORY');
        $brandOptions = $this->referenceOptions($businessId, 'PRODUCT_BRAND');
        $uomOptions = $this->referenceOptions($businessId, 'PRODUCT_UOM');

        $inventoryCategories = $this->mergeLegacyValues(
            $inventoryCategories,
            RetailProduct::query()->where('business_id', $businessId)
                ->where(function ($query) {
                    $query->whereNull('stock_type')->orWhere('stock_type', 'INVENTORY');
                })
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category'),
        );

        $nonInventoryCategories = $this->mergeLegacyValues(
            $nonInventoryCategories,
            RetailProduct::query()->where('business_id', $businessId)
                ->where('stock_type', 'NON_INVENTORY')
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category'),
        );

        $assetCategories = $this->mergeLegacyValues(
            $assetCategories,
            RetailProduct::query()->where('business_id', $businessId)
                ->where('stock_type', 'ASSET')
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category'),
        );

        $brandOptions = $this->mergeLegacyValues(
            $brandOptions,
            RetailProduct::query()->where('business_id', $businessId)
                ->whereNotNull('brand')->where('brand', '!=', '')
                ->distinct()->orderBy('brand')->pluck('brand'),
        );
        $uomOptions = $this->mergeLegacyValues(
            $uomOptions,
            RetailProduct::query()->where('business_id', $businessId)
                ->whereNotNull('unit')->where('unit', '!=', '')
                ->distinct()->orderBy('unit')->pluck('unit')
                ->merge(RetailProduct::query()->where('business_id', $businessId)->whereNotNull('purchase_uom')->where('purchase_uom', '!=', '')->distinct()->orderBy('purchase_uom')->pluck('purchase_uom'))
                ->merge(RetailProduct::query()->where('business_id', $businessId)->whereNotNull('selling_uom')->where('selling_uom', '!=', '')->distinct()->orderBy('selling_uom')->pluck('selling_uom'))
                ->merge(RetailProduct::query()->where('business_id', $businessId)->whereNotNull('consumption_uom')->where('consumption_uom', '!=', '')->distinct()->orderBy('consumption_uom')->pluck('consumption_uom')),
        );

        return response()->json([
            'products' => $service->list($user, $request->only(['product_id', 'search', 'category', 'stock_type', 'status', 'branch_id', 'retail', 'commission'])),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'ACTIVE')
                ->orderBy('name')->get(['id', 'code', 'name']),
            'selected_branch_id' => $branchId,
            'categories' => $inventoryCategories->pluck('value')->values(),
            'reference_options' => [
                'product_categories' => $inventoryCategories->values(),
                'non_inventory_categories' => $nonInventoryCategories->values(),
                'asset_categories' => $assetCategories->values(),
                'categories_by_stock_type' => [
                    'INVENTORY' => $inventoryCategories->values(),
                    'NON_INVENTORY' => $nonInventoryCategories->values(),
                    'ASSET' => $assetCategories->values(),
                ],
                'product_brands' => $brandOptions->values(),
                'product_uoms' => $uomOptions->values(),
                'item_options' => RetailProduct::query()->where('business_id', $businessId)->orderBy('name')->get(['id','sku','name','stock_type','status'])->map(fn (RetailProduct $item) => [
                    'id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'stock_type' => $item->stock_type, 'status' => $item->status,
                    'label' => $item->name,
                ])->values(),
            ],
        ]);
    }

    public function store(SaveRetailProductRequest $request, RetailProductService $service): JsonResponse
    {
        try {
            $product = $service->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Inventory item created.', 'product' => $product], 201);
    }

    public function update(SaveRetailProductRequest $request, RetailProduct $retailProduct, RetailProductService $service): JsonResponse
    {
        try {
            $product = $service->update($request->user(), $retailProduct, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Inventory item updated.', 'product' => $product]);
    }

    public function approve(Request $request, RetailProduct $retailProduct, RetailProductService $service): JsonResponse
    {
        try {
            $product = $service->approve($request->user(), $retailProduct);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Inventory item approved.', 'product' => $product]);
    }

    public function toggle(Request $request, RetailProduct $retailProduct, RetailProductService $service): JsonResponse
    {
        try {
            $product = $service->toggle($request->user(), $retailProduct);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Inventory item status updated.', 'product' => $product]);
    }

    public function adjustStock(
        AdjustRetailStockRequest $request,
        RetailProduct $retailProduct,
        InventoryAdjustmentService $approvalService,
        RetailProductService $productService,
    ): JsonResponse {
        try {
            // Legacy compatibility endpoint. Every manual stock adjustment, including
            // Super Admin requests, enters the same maker/approver workflow. There is
            // no direct-post bypass because that would defeat the approval control.
            $adjustment = $approvalService->create($request->user(), $retailProduct, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock adjustment submitted for approval.',
            'request' => [
                'id' => $adjustment->id,
                'code' => $adjustment->code,
                'status' => $adjustment->status,
                'quantity_change' => (float) $adjustment->quantity_change,
            ],
        ], 201);
    }

    private function referenceOptions(int $businessId, string $groupCode): Collection
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->where('active', true)
            ->first();

        if (! $group) {
            return collect();
        }

        return ReferenceValue::query()
            ->where('reference_group_id', $group->id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('display_value')
            ->get()
            ->map(fn (ReferenceValue $value) => [
                'code' => $value->code,
                'value' => $value->display_value,
            ]);
    }

    private function mergeLegacyValues(Collection $options, Collection $values): Collection
    {
        $seen = $options->pluck('value')->map(fn ($value) => strtolower(trim((string) $value)))->all();

        foreach ($values as $value) {
            $display = trim((string) $value);
            if ($display === '' || in_array(strtolower($display), $seen, true)) {
                continue;
            }

            $options->push([
                'code' => 'LEGACY_'.substr(md5($display), 0, 10),
                'value' => $display,
            ]);
            $seen[] = strtolower($display);
        }

        return $options->sortBy(fn (array $option) => strtolower((string) $option['value']))->values();
    }
}
