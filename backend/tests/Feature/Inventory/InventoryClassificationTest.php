<?php

use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);
    $this->seed(\Database\Seeders\ProductMasterReferenceSeeder::class);
    $this->user = foundationUser('ROLE-SA');
});

function inventoryClassificationPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => null,
        'barcode' => null,
        'name' => 'Classification Test Item',
        'category' => 'Office Supplies',
        'brand' => null,
        'unit' => 'pc',
        'stock_type' => 'NON_INVENTORY',
        'unit_cost' => 100,
        'selling_price' => 999,
        'reorder_point' => 9,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'notes' => null,
    ], $overrides);
}

test('inventory masterlist exposes stock type specific reference categories', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/products')
        ->assertOk()
        ->assertJsonFragment(['value' => 'Styling'])
        ->assertJsonFragment(['value' => 'Office Supplies'])
        ->assertJsonFragment(['value' => 'Barber Equipment']);
});

test('non inventory and asset items do not carry retail or quantity controls', function () {
    $nonInventoryId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', inventoryClassificationPayload())
        ->assertCreated()
        ->assertJsonPath('product.stock_type', 'NON_INVENTORY')
        ->assertJsonPath('product.selling_price', '0.00')
        ->assertJsonPath('product.reorder_point', '0.0000')
        ->assertJsonPath('product.commission_eligible', false)
        ->json('product.id');

    expect(InventoryStock::query()->where('retail_product_id', $nonInventoryId)->exists())->toBeFalse();

    $assetId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', inventoryClassificationPayload([
            'name' => 'Barber Chair',
            'category' => 'Barber Equipment',
            'stock_type' => 'ASSET',
        ]))
        ->assertCreated()
        ->assertJsonPath('product.stock_type', 'ASSET')
        ->assertJsonPath('product.reorder_point', '0.0000')
        ->assertJsonPath('product.commission_eligible', false)
        ->json('product.id');

    expect(InventoryStock::query()->where('retail_product_id', $assetId)->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products/'.$assetId.'/adjust-stock', [
            'branch_id' => $this->user->default_branch_id,
            'quantity_change' => 1,
            'reason_reference_id' => $this->workflowReasonId('OTHER'),
            'reason' => 'Assets are not quantity tracked',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Manual stock adjustments are only available for quantity-tracked Inventory items.');
});
