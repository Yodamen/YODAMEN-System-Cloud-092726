<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServiceCompletionEvent;
use App\Models\ServiceConsumption;
use App\Models\ServiceConsumptionRecipe;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ServiceConsumptionPostingService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Posts recipe materials for one completed Visit service line.
     *
     * The caller already runs inside the service-completion transaction. This
     * method is additionally safe to call again: visit_service_line_id is unique
     * and an existing posting is returned without deducting inventory twice.
     */
    public function post(
        User $actor,
        Visit $visit,
        VisitServiceLine $line,
        ServiceCompletionEvent $event,
    ): ?ServiceConsumption {
        if ((int) $visit->business_id !== (int) $actor->business_id
            || (int) $line->visit_id !== (int) $visit->id
            || (int) $event->visit_service_line_id !== (int) $line->id) {
            throw new DomainException('Service consumption context is invalid.');
        }

        $existing = ServiceConsumption::query()
            ->where('business_id', $actor->business_id)
            ->where('visit_service_line_id', $line->id)
            ->with(['branch', 'lines.product', 'creator'])
            ->first();
        if ($existing) return $existing;

        $recipe = ServiceConsumptionRecipe::query()
            ->where('business_id', $actor->business_id)
            ->where('service_id', $line->service_id)
            ->where('status', 'ACTIVE')
            ->with(['lines.product'])
            ->first();

        // Recipes are intentionally optional. Services without an ACTIVE recipe
        // complete normally and simply do not create a material consumption.
        if (! $recipe || $recipe->lines->isEmpty()) return null;

        $sourceType = strtoupper((string) ($line->source_type ?: 'NEW_SERVICE'));
        $service = Service::query()
            ->with('serviceCount')
            ->where('business_id', $actor->business_id)
            ->whereKey($line->service_id)
            ->first();

        $isMultiSession = $service
            && (strtoupper((string) $service->serviceCount?->code) === 'MULTI_SESSION'
                || (int) ($service->number_of_sessions ?? 1) > 1);
        $packageSessions = $isMultiSession ? max(1, (int) ($service->number_of_sessions ?? 1)) : 1;

        // Recipe meaning:
        // - SINGLE_SESSION: the saved recipe is the material for one completed service.
        // - MULTI_SESSION: the saved recipe is the TOTAL material allocation for the
        //   entire package. One completed session consumes exactly 1 / N of the recipe,
        //   where N is the configured package session count.
        //
        // A package/service-credit completion always represents one rendered session,
        // even when a legacy Visit line still carries the package entitlement quantity.
        $renderedSessions = $isMultiSession || in_array($sourceType, ['NEW_PACKAGE', 'SERVICE_CREDIT'], true)
            ? 1.0
            : max(1, (float) ($line->quantity ?: 1));
        $recipeScale = $isMultiSession
            ? round($renderedSessions / $packageSessions, 8)
            : $renderedSessions;
        $serviceQuantity = $renderedSessions;
        $plans = collect();

        // Lock every stock row before posting. Service consumption intentionally
        // permits negative stock: operational service completion is never blocked
        // merely because the system balance is short. Later receipts/counts can
        // offset the deficit while the movement history preserves the shortage.
        foreach ($recipe->lines->sortBy('retail_product_id')->values() as $recipeLine) {
            $product = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($recipeLine->retail_product_id)
                ->first();

            if (! $product || strtoupper((string) $product->status) !== 'ACTIVE' || strtoupper((string) $product->stock_type) !== 'INVENTORY') {
                throw new DomainException(($recipeLine->product?->name ?: 'A recipe material').' is no longer an active Inventory item. Update the Service Material Recipe before completing this service.');
            }

            $factor = max(0.000001, (float) ($recipeLine->consumption_to_basic_factor_snapshot ?: 1));
            $consumptionQuantity = round((float) $recipeLine->quantity_per_service * $recipeScale, 4);
            $basicQuantity = round($consumptionQuantity / $factor, 4);
            if ($basicQuantity <= 0) continue;

            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $visit->branch_id, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();

            $onHand = round((float) $stock->on_hand, 4);
            $reserved = round((float) $stock->reserved, 4);
            $availableBefore = round($onHand - $reserved, 4);
            $after = round($onHand - $basicQuantity, 4);
            $availableAfter = round($after - $reserved, 4);

            $plans->push([
                'recipe_line' => $recipeLine,
                'product' => $product,
                'stock' => $stock,
                'consumption_quantity' => $consumptionQuantity,
                'basic_quantity' => $basicQuantity,
                'factor' => $factor,
                'before' => $onHand,
                'after' => $after,
                'available_before' => $availableBefore,
                'available_after' => $availableAfter,
                'went_negative' => $after < 0,
                'weighted_cost' => round((float) $product->unit_cost, 4),
            ]);
        }

        if ($plans->isEmpty()) return null;

        $postedAt = $event->completed_at ?: now();
        $consumption = ServiceConsumption::query()->create([
            'business_id' => $actor->business_id,
            'code' => $this->codes->next(
                'SERVICE_CONSUMPTION:'.$postedAt->format('my'),
                'SCN-'.$postedAt->format('my').'-',
                6,
                $actor->business_id,
            ),
            'branch_id' => $visit->branch_id,
            'visit_id' => $visit->id,
            'visit_service_line_id' => $line->id,
            'service_completion_event_id' => $event->id,
            'service_id' => $line->service_id,
            'service_name_snapshot' => $line->service_name_snapshot ?: ($line->service?->name ?: 'Service'),
            'service_quantity' => $serviceQuantity,
            'total_cost' => 0,
            'status' => 'POSTED',
            'posted_at' => $postedAt,
            'created_by' => $actor->id,
        ]);

        $totalCost = 0.0;
        foreach ($plans as $plan) {
            /** @var InventoryStock $stock */
            $stock = $plan['stock'];
            /** @var RetailProduct $product */
            $product = $plan['product'];
            $stock->update(['on_hand' => $plan['after']]);

            $movement = InventoryMovement::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $visit->branch_id,
                'retail_product_id' => $product->id,
                'movement_type' => 'SERVICE_CONSUMPTION',
                'quantity_change' => -$plan['basic_quantity'],
                'quantity_before' => $plan['before'],
                'quantity_after' => $plan['after'],
                'unit_cost_snapshot' => $plan['weighted_cost'],
                'reference_code' => $consumption->code,
                'reason' => 'Service consumption · '.$consumption->service_name_snapshot.' · '.$line->code,
                'created_by' => $actor->id,
                'movement_at' => $postedAt,
            ]);

            $cost = round($plan['basic_quantity'] * $plan['weighted_cost'], 2);
            $totalCost = round($totalCost + $cost, 2);
            $recipeLine = $plan['recipe_line'];

            $consumption->lines()->create([
                'retail_product_id' => $product->id,
                'inventory_movement_id' => $movement->id,
                'item_name_snapshot' => $product->name,
                'consumption_quantity' => $plan['consumption_quantity'],
                'consumption_uom_snapshot' => $recipeLine->consumption_uom_snapshot ?: ($product->consumption_uom ?: $product->unit),
                'consumption_to_basic_factor_snapshot' => $plan['factor'],
                'basic_quantity' => $plan['basic_quantity'],
                'basic_uom_snapshot' => $product->unit,
                'weighted_average_basic_cost' => $plan['weighted_cost'],
                'cost_amount' => $cost,
                'quantity_before' => $plan['before'],
                'quantity_after' => $plan['after'],
            ]);
        }

        $consumption->update(['total_cost' => $totalCost]);
        $fresh = $consumption->fresh(['branch', 'lines.product', 'creator']);

        $this->audit->record(
            action: 'POST_SERVICE_CONSUMPTION',
            module: 'service-consumption',
            entityType: ServiceConsumption::class,
            entityId: $fresh->id,
            after: [
                'code' => $fresh->code,
                'visit_service_line_id' => $line->id,
                'service_id' => $line->service_id,
                'total_cost' => (float) $fresh->total_cost,
                'materials' => $fresh->lines->map(fn ($row) => [
                    'retail_product_id' => $row->retail_product_id,
                    'consumption_quantity' => (float) $row->consumption_quantity,
                    'consumption_uom' => $row->consumption_uom_snapshot,
                    'basic_quantity' => (float) $row->basic_quantity,
                    'basic_uom' => $row->basic_uom_snapshot,
                    'cost_amount' => (float) $row->cost_amount,
                    'quantity_before' => (float) $row->quantity_before,
                    'quantity_after' => (float) $row->quantity_after,
                    'negative_stock' => (float) $row->quantity_after < 0,
                ])->values()->all(),
            ],
            actor: $actor,
            metadata: [
                'visit_id' => $visit->id,
                'visit_code' => $visit->code,
                'service_completion_event_id' => $event->id,
                'recipe_scope' => $isMultiSession ? 'FULL_MULTI_SESSION_PACKAGE' : 'PER_COMPLETED_SERVICE',
                'configured_package_sessions' => $packageSessions,
                'rendered_sessions_posted' => $renderedSessions,
                'recipe_scale_applied' => $recipeScale,
                'negative_stock_materials' => $fresh->lines->filter(fn ($row) => (float) $row->quantity_after < 0)->count(),
            ],
        );

        return $fresh;
    }
}
