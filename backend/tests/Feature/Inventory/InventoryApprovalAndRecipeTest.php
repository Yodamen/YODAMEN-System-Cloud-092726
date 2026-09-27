<?php

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use App\Models\ServiceConsumptionRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);
    $this->seed(\Database\Seeders\ProductMasterReferenceSeeder::class);
    $this->admin = foundationUser('ROLE-SA');
    $this->manager = foundationUser('ROLE-MGR');
});

test('manual stock adjustment stays pending until approved', function () {
    $productId = $this->actingAs($this->admin)->postJson('/api/v1/inventory/products', [
        'name' => 'Approval Test Wax',
        'category' => 'Styling',
        'unit' => 'pc',
        'stock_type' => 'INVENTORY',
        'unit_cost' => 100,
        'selling_price' => 200,
        'retail_available' => true,
        'reorder_point' => 0,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    InventoryStock::query()->updateOrCreate(
        [
            'branch_id' => $this->admin->default_branch_id,
            'retail_product_id' => $productId,
        ],
        [
            'business_id' => $this->admin->business_id,
            'on_hand' => 10,
            'reserved' => 0,
        ],
    );

    $request = $this->actingAs($this->manager)->postJson('/api/v1/inventory/adjustment-requests', [
        'retail_product_id' => $productId,
        'branch_id' => $this->manager->default_branch_id,
        'quantity_change' => -2,
        'reason_reference_id' => $this->workflowReasonId('DAMAGE_BREAKAGE'),
        'reason' => 'Damaged during handling',
    ])->assertCreated()->assertJsonPath('request.status', 'PENDING');

    expect((int) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(10)
        ->and(InventoryMovement::query()->where('retail_product_id', $productId)->where('movement_type', 'ADJUSTMENT')->count())->toBe(0);

    $this->actingAs($this->manager)->postJson('/api/v1/inventory/adjustment-requests/'.$request->json('request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    expect((int) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(8)
        ->and(InventoryMovement::query()->where('retail_product_id', $productId)->where('movement_type', 'ADJUSTMENT')->count())->toBe(1);
});

test('service material recipe stores inventory materials and estimated cost', function () {
    $serviceId = $this->actingAs($this->admin)->postJson('/api/v1/admin/services', [
        'name' => 'Recipe Test Service',
        'category_code' => 'HAIRCUT',
        'service_count_code' => 'SINGLE_SESSION',
        'number_of_sessions' => 1,
        'duration_minutes' => 30,
        'status_code' => 'ACTIVE',
        'initial_price' => 500,
        'effective_from' => now()->toDateString(),
    ])->assertCreated()->json('service.id');

    $productId = $this->actingAs($this->admin)->postJson('/api/v1/inventory/products', [
        'name' => 'Scalp Serum Vial',
        'category' => 'Styling',
        'unit' => 'Vial',
        'purchase_uom' => 'box',
        'purchase_to_basic_factor' => 10,
        'purchase_uom_cost' => 180,
        'consumption_uom' => 'ml',
        'consumption_to_basic_factor' => 8,
        'selling_uom' => 'Vial',
        'selling_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'unit_cost' => 18,
        'selling_price' => 0,
        'retail_available' => false,
        'reorder_point' => 2,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    $this->actingAs($this->admin)->getJson('/api/v1/inventory/products?product_id='.$productId)
        ->assertOk()
        ->assertJsonPath('products.0.consumption_uom', 'ml')
        ->assertJsonPath('products.0.basic_to_consumption_factor', 8)
        ->assertJsonPath('products.0.consumption_uom_cost', 2.25)
        ->assertJsonPath('products.0.weighted_average_consumption_cost', 2.25);

    $this->actingAs($this->manager)->postJson('/api/v1/inventory/service-consumption/recipes', [
        'service_id' => $serviceId,
        'status' => 'ACTIVE',
        'lines' => [[
            'retail_product_id' => $productId,
            'quantity_per_service' => 4,
        ]],
    ])->assertCreated()
      ->assertJsonPath('recipe.lines.0.consumption_uom', 'ml')
      ->assertJsonPath('recipe.lines.0.quantity_per_service', 4)
      ->assertJsonPath('recipe.lines.0.basic_to_consumption_factor', 8)
      ->assertJsonPath('recipe.lines.0.basic_quantity_per_service', 0.5)
      ->assertJsonPath('recipe.lines.0.basic_uom', 'Vial')
      ->assertJsonPath('recipe.lines.0.weighted_average_consumption_cost', 2.25)
      ->assertJsonPath('recipe.estimated_material_cost', 9);

    expect(ServiceConsumptionRecipe::query()->where('service_id', $serviceId)->count())->toBe(1);
});

test('legacy adjust stock endpoint cannot bypass approval even for super admin', function () {
    $productId = $this->actingAs($this->admin)->postJson('/api/v1/inventory/products', [
        'name' => 'Legacy Adjustment Control Item',
        'category' => 'Styling',
        'unit' => 'pc',
        'stock_type' => 'INVENTORY',
        'unit_cost' => 50,
        'selling_price' => 100,
        'retail_available' => true,
        'reorder_point' => 0,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    InventoryStock::query()->updateOrCreate(
        ['branch_id' => $this->admin->default_branch_id, 'retail_product_id' => $productId],
        ['business_id' => $this->admin->business_id, 'on_hand' => 5, 'reserved' => 0],
    );

    $this->actingAs($this->admin)->postJson('/api/v1/inventory/products/'.$productId.'/adjust-stock', [
        'branch_id' => $this->admin->default_branch_id,
        'quantity_change' => -1,
        'reason_reference_id' => $this->workflowReasonId('OTHER'),
        'reason' => 'Legacy endpoint control test',
    ])->assertCreated()->assertJsonPath('request.status', 'PENDING');

    expect((float) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(5.0)
        ->and(InventoryMovement::query()->where('retail_product_id', $productId)->where('movement_type', 'ADJUSTMENT')->count())->toBe(0);
});

test('inventory item with negative stock cannot be deactivated until the deficit is resolved', function () {
    $productId = $this->actingAs($this->admin)->postJson('/api/v1/inventory/products', [
        'name' => 'Negative Deactivation Control',
        'category' => 'Styling',
        'unit' => 'pc',
        'stock_type' => 'INVENTORY',
        'unit_cost' => 50,
        'selling_price' => 0,
        'retail_available' => false,
        'reorder_point' => 0,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    InventoryStock::query()->updateOrCreate(
        ['branch_id' => $this->admin->default_branch_id, 'retail_product_id' => $productId],
        ['business_id' => $this->admin->business_id, 'on_hand' => -0.5, 'reserved' => 0],
    );

    $this->actingAs($this->admin)->patchJson('/api/v1/inventory/products/'.$productId.'/toggle')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This item cannot be deactivated while stock remains on hand or reserved.');
});


test('positive manual adjustment can partially offset service-created negative stock', function () {
    $productId = $this->actingAs($this->admin)->postJson('/api/v1/inventory/products', [
        'name' => 'Negative Offset Adjustment Item',
        'category' => 'Styling',
        'unit' => 'Vial',
        'stock_type' => 'INVENTORY',
        'unit_cost' => 10,
        'selling_price' => 0,
        'retail_available' => false,
        'reorder_point' => 0,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    InventoryStock::query()->updateOrCreate(
        ['branch_id' => $this->admin->default_branch_id, 'retail_product_id' => $productId],
        ['business_id' => $this->admin->business_id, 'on_hand' => -1.0, 'reserved' => 0],
    );

    $requestId = $this->actingAs($this->manager)->postJson('/api/v1/inventory/adjustment-requests', [
        'retail_product_id' => $productId,
        'branch_id' => $this->manager->default_branch_id,
        'quantity_change' => 0.25,
        'reason_reference_id' => $this->workflowReasonId('FOUND_STOCK'),
        'reason' => 'Physical stock discovered during reconciliation',
    ])->assertCreated()->json('request.id');

    $this->actingAs($this->manager)
        ->postJson('/api/v1/inventory/adjustment-requests/'.$requestId.'/approve')
        ->assertOk();

    expect((float) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(-0.75);
});
