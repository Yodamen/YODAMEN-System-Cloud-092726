<?php

namespace App\Services\Inventory;

use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServiceConsumption;
use App\Models\ServiceConsumptionRecipe;
use App\Models\User;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ServiceConsumptionRecipeService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(User $actor): array
    {
        $services = Service::query()
            ->where('business_id', $actor->business_id)
            ->with(['category', 'statusReference'])
            ->orderBy('name')
            ->get();

        $recipes = ServiceConsumptionRecipe::query()
            ->where('business_id', $actor->business_id)
            ->with(['service.category', 'lines.product'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ServiceConsumptionRecipe $recipe) => $this->serialize($recipe))
            ->values();

        $items = RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('stock_type', 'INVENTORY')
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit', 'unit_cost', 'retail_available', 'consumption_uom', 'consumption_to_basic_factor'])
            ->map(function (RetailProduct $item) {
                // Legacy DB column name is retained, but the business meaning is:
                // 1 Basic UOM = N Consumption UOM.
                $consumptionFactor = max(0.000001, (float) ($item->consumption_to_basic_factor ?: 1));
                $weightedBasicCost = (float) ($item->unit_cost ?? 0);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'basic_uom' => $item->unit,
                    'consumption_uom' => $item->consumption_uom ?: $item->unit,
                    'consumption_to_basic_factor' => $consumptionFactor,
                    'basic_to_consumption_factor' => $consumptionFactor,
                    'weighted_average_basic_cost' => $weightedBasicCost,
                    'weighted_average_cost' => round($weightedBasicCost / $consumptionFactor, 4),
                    'weighted_average_consumption_cost' => round($weightedBasicCost / $consumptionFactor, 4),
                    'sellable' => (bool) $item->retail_available,
                ];
            })->values();

        $consumptions = ServiceConsumption::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'creator', 'lines.product', 'visit.booking', 'visit.sale.booking', 'visit.sale.actionRequests'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ServiceConsumption $row) => $this->serializeConsumption($row))
            ->values();

        return [
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'category' => $service->category?->display_value,
                'status' => $service->statusReference?->code,
            ])->values(),
            'items' => $items,
            'recipes' => $recipes,
            'consumptions' => $consumptions,
        ];
    }

    public function save(User $actor, array $data, ?ServiceConsumptionRecipe $recipe = null): ServiceConsumptionRecipe
    {
        $service = Service::query()->where('business_id', $actor->business_id)->find($data['service_id']);
        if (! $service) throw new DomainException('Select a valid service.');

        if ($recipe && (int) $recipe->business_id !== (int) $actor->business_id) abort(404);

        $existing = ServiceConsumptionRecipe::query()
            ->where('business_id', $actor->business_id)
            ->where('service_id', $service->id)
            ->when($recipe, fn ($query) => $query->where('id', '!=', $recipe->id))
            ->exists();
        if ($existing) throw new DomainException('This service already has a material recipe. Edit the existing recipe instead.');

        $lines = collect($data['lines'] ?? []);
        if ($lines->isEmpty()) throw new DomainException('Add at least one material to the service recipe.');

        $itemIds = $lines->pluck('retail_product_id')->map(fn ($id) => (int) $id)->all();
        $items = RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('stock_type', 'INVENTORY')
            ->where('status', 'ACTIVE')
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        if ($items->count() !== count(array_unique($itemIds))) {
            throw new DomainException('One or more recipe materials are invalid or inactive.');
        }

        return DB::transaction(function () use ($actor, $data, $recipe, $service, $lines, $items) {
            $before = $recipe?->load('lines')->toArray();
            $record = $recipe ?: new ServiceConsumptionRecipe();
            $record->fill([
                'business_id' => $actor->business_id,
                'service_id' => $service->id,
                'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')),
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);
            if (! $record->exists) $record->created_by = $actor->id;
            $record->save();

            $record->lines()->delete();
            foreach ($lines->values() as $index => $line) {
                $item = $items->get((int) $line['retail_product_id']);
                $quantity = round((float) $line['quantity_per_service'], 4);
                if ($quantity <= 0) throw new DomainException('Material quantity must be greater than zero.');
                $consumptionUom = $item->consumption_uom ?: $item->unit;
                $consumptionFactor = max(0.000001, (float) ($item->consumption_to_basic_factor ?: 1));
                // Consumption Conversion means 1 Basic UOM = N Consumption UOM.
                $basicQuantity = round($quantity / $consumptionFactor, 4);

                $record->lines()->create([
                    'retail_product_id' => $item->id,
                    'quantity_per_service' => $quantity,
                    'consumption_uom_snapshot' => $consumptionUom,
                    'consumption_to_basic_factor_snapshot' => $consumptionFactor,
                    'basic_quantity_per_service' => $basicQuantity,
                    'basic_uom_snapshot' => $item->unit,
                    'unit_cost_snapshot' => (float) $item->unit_cost,
                    'sort_order' => $index + 1,
                ]);
            }

            $fresh = $record->fresh(['service.category', 'lines.product']);
            $this->audit->record(
                action: $recipe ? 'UPDATE_SERVICE_MATERIAL_RECIPE' : 'CREATE_SERVICE_MATERIAL_RECIPE',
                module: 'service-consumption',
                entityType: ServiceConsumptionRecipe::class,
                entityId: $fresh->id,
                before: $before,
                after: $fresh->toArray(),
                actor: $actor,
            );

            return $fresh;
        });
    }


    public function serializeConsumption(ServiceConsumption $consumption): array
    {
        $consumption->loadMissing(['branch', 'creator', 'lines.product', 'visit.booking', 'visit.sale.booking', 'visit.sale.actionRequests']);

        $sale = $consumption->visit?->sale;
        $pendingRequest = $sale?->actionRequests
            ?->first(fn ($request) => in_array(strtoupper((string) $request->status), ['PENDING_MANAGER_APPROVAL', 'APPROVED_FOR_POSTING'], true)
                && in_array(strtoupper((string) $request->action_type), ['CORRECTION', 'REVERSAL'], true));
        $reversed = in_array(strtoupper((string) ($consumption->correction_status ?? '')), ['REVERSED', 'REMOVED'], true)
            || strtoupper((string) ($sale?->status ?? '')) === 'REVERSED';
        $displayStatus = $pendingRequest ? 'PENDING' : ($reversed ? 'REVERSED' : 'CONSUMED');

        return [
            'id' => $consumption->id,
            'code' => $consumption->code,
            'service_name' => $consumption->service_name_snapshot,
            'booking_code' => $consumption->visit?->booking?->code ?: $consumption->visit?->sale?->booking?->code,
            'service_quantity' => (float) $consumption->service_quantity,
            'status' => $consumption->status,
            'display_status' => $displayStatus,
            'pending_action' => $pendingRequest ? [
                'code' => $pendingRequest->code,
                'action_type' => $pendingRequest->action_type,
                'status' => $pendingRequest->status,
            ] : null,
            'total_cost' => (float) $consumption->total_cost,
            'posted_at' => $consumption->posted_at?->toIso8601String(),
            'branch' => [
                'id' => $consumption->branch?->id,
                'name' => $consumption->branch?->name,
            ],
            'posted_by' => $consumption->creator?->display_name,
            'materials_count' => $consumption->lines->count(),
            'negative_stock_materials' => $consumption->lines->filter(fn ($line) => (float) $line->quantity_after < 0)->count(),
            'has_negative_stock' => $consumption->lines->contains(fn ($line) => (float) $line->quantity_after < 0),
            'lines' => $consumption->lines->map(fn ($line) => [
                'id' => $line->id,
                'retail_product_id' => $line->retail_product_id,
                'item_name' => $line->item_name_snapshot,
                'consumption_quantity' => (float) $line->consumption_quantity,
                'consumption_uom' => $line->consumption_uom_snapshot,
                'basic_quantity' => (float) $line->basic_quantity,
                'basic_uom' => $line->basic_uom_snapshot,
                'weighted_average_basic_cost' => (float) $line->weighted_average_basic_cost,
                'cost_amount' => (float) $line->cost_amount,
                'quantity_before' => (float) $line->quantity_before,
                'quantity_after' => (float) $line->quantity_after,
                'negative_stock' => (float) $line->quantity_after < 0,
                'stock_deficit' => max(0, round(-(float) $line->quantity_after, 4)),
            ])->values(),
        ];
    }

    public function serialize(ServiceConsumptionRecipe $recipe): array
    {
        $recipe->loadMissing(['service.category', 'lines.product']);
        $lines = $recipe->lines->map(function ($line) {
            $currentBasicCost = (float) ($line->product?->unit_cost ?? $line->unit_cost_snapshot ?? 0);
            $consumptionUom = $line->consumption_uom_snapshot ?: ($line->product?->consumption_uom ?: $line->product?->unit ?: $line->basic_uom_snapshot);
            $consumptionFactor = max(0.000001, (float) ($line->consumption_to_basic_factor_snapshot ?: $line->product?->consumption_to_basic_factor ?: 1));
            // Always calculate from the corrected conversion semantics so stale cached
            // basic quantities do not preserve the former reversed interpretation.
            $basicQuantity = round((float) $line->quantity_per_service / $consumptionFactor, 4);
            $currentConsumptionCost = round($currentBasicCost / $consumptionFactor, 4);
            return [
                'id' => $line->id,
                'retail_product_id' => $line->retail_product_id,
                'item_name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'quantity_per_service' => (float) $line->quantity_per_service,
                'consumption_uom' => $consumptionUom,
                'consumption_to_basic_factor' => $consumptionFactor,
                'basic_to_consumption_factor' => $consumptionFactor,
                'basic_quantity_per_service' => $basicQuantity,
                'basic_uom' => $line->product?->unit ?: $line->basic_uom_snapshot,
                'weighted_average_basic_cost' => $currentBasicCost,
                'weighted_average_cost' => $currentConsumptionCost,
                'weighted_average_consumption_cost' => $currentConsumptionCost,
                'estimated_cost' => round($basicQuantity * $currentBasicCost, 2),
            ];
        })->values();

        return [
            'id' => $recipe->id,
            'service_id' => $recipe->service_id,
            'service' => [
                'id' => $recipe->service?->id,
                'code' => $recipe->service?->code,
                'name' => $recipe->service?->name,
                'category' => $recipe->service?->category?->display_value,
            ],
            'status' => $recipe->status,
            'notes' => $recipe->notes,
            'materials_count' => $lines->count(),
            'estimated_material_cost' => round((float) $lines->sum('estimated_cost'), 2),
            'lines' => $lines,
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];
    }
}
