<?php

use App\Models\InventoryReceiptLine;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
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

function uomProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => null,
        'name' => 'UOM Test Product',
        'category' => 'Styling',
        'brand' => null,
        'unit' => 'pc',
        'purchase_uom' => 'box',
        'purchase_to_basic_factor' => 12,
        'purchase_uom_cost' => 120,
        'selling_uom' => 'pc',
        'selling_to_basic_factor' => 1,
        'consumption_uom' => 'pc',
        'consumption_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'retail_available' => false,
        'unit_cost' => 10,
        'selling_price' => 0,
        'reorder_point' => 2,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
        'notes' => null,
    ], $overrides);
}

test('inventory item stores purchase and basic uom conversion and retail availability', function () {
    $id = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', uomProductPayload())
        ->assertCreated()->json('product.id');

    $this->actingAs($this->user)->getJson('/api/v1/inventory/products?product_id='.$id)
        ->assertOk()
        ->assertJsonPath('products.0.basic_uom', 'pc')
        ->assertJsonPath('products.0.purchase_uom', 'box')
        ->assertJsonPath('products.0.purchase_to_basic_factor', 12)
        ->assertJsonPath('products.0.purchase_uom_cost', 120)
        ->assertJsonPath('products.0.basic_uom_cost', 10)
        ->assertJsonPath('products.0.selling_uom', 'pc')
        ->assertJsonPath('products.0.consumption_uom', 'pc')
        ->assertJsonPath('products.0.basic_to_consumption_factor', 1)
        ->assertJsonPath('products.0.consumption_uom_cost', 10)
        ->assertJsonPath('products.0.weighted_average_consumption_cost', 10)
        ->assertJsonPath('products.0.retail_available', false);
});

test('supplier masterlist maps the items a supplier can provide', function () {
    $productId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', uomProductPayload())
        ->assertCreated()->json('product.id');

    $supplierId = $this->actingAs($this->user)->postJson('/api/v1/inventory/suppliers', [
        'company_name' => 'Test Supplier Co.',
        'code_name' => 'Test Supplier',
        'contact_person' => 'Alex Supplier',
        'contact_number' => '09170000000',
        'channel' => 'Viber',
        'address' => 'Test Address',
        'store_link' => 'https://example.com/store',
        'status' => 'ACTIVE',
        'item_ids' => [$productId],
    ])->assertCreated()->assertJsonPath('supplier.item_ids.0', $productId)->json('supplier.id');

    expect($supplierId)->toBeGreaterThan(0);
});

test('stock receipt converts purchase uom to basic uom and uses basic weighted cost', function () {
    $productId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', uomProductPayload())
        ->assertCreated()->json('product.id');

    $supplierId = $this->actingAs($this->user)->postJson('/api/v1/inventory/suppliers', [
        'company_name' => 'Receipt Supplier', 'code_name' => 'Receipt Supplier', 'channel' => 'Phone', 'status' => 'ACTIVE', 'item_ids' => [$productId],
    ])->assertCreated()->json('supplier.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => now()->toDateString(),
        'supplier_id' => $supplierId,
        'reference_no' => 'INV-001',
        'delivery_fee' => 75,
        'lines' => [[
            'retail_product_id' => $productId,
            'purchase_quantity' => 2,
            'purchase_uom' => 'box',
            'conversion_factor' => 12,
            'purchase_unit_cost' => 120,
            'update_product_conversion' => false,
        ]],
    ])->assertCreated()
      ->assertJsonPath('receipt.lines.0.quantity_received', 24)
      ->assertJsonPath('receipt.lines.0.purchase_quantity', 2)
      ->assertJsonPath('receipt.lines.0.unit_cost', 10)
      ->assertJsonPath('receipt.delivery_fee', 75)
      ->assertJsonPath('receipt.total_cost', 240)
      ->assertJsonPath('receipt.grand_total', 315);

    expect((int) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(24);
    expect((float) InventoryReceiptLine::query()->where('retail_product_id', $productId)->value('line_total'))->toBe(240.0);
    expect((float) RetailProduct::query()->findOrFail($productId)->unit_cost)->toBe(10.0);
});

test('inventory dashboard preserves negative stock and flags the sku instead of clamping it to zero', function () {
    $productId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', uomProductPayload([
        'name' => 'Negative Stock Test',
        'sku' => 'SKU-NEG-001',
    ]))->assertCreated()->json('product.id');

    InventoryStock::query()->where('retail_product_id', $productId)->update([
        'on_hand' => -0.5,
        'reserved' => 0,
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard?branch_id='.$this->user->default_branch_id.'&product_id='.$productId)
        ->assertOk()
        ->assertJsonPath('rows.0.on_hand', -0.5)
        ->assertJsonPath('rows.0.available', -0.5)
        ->assertJsonPath('rows.0.status', 'NEGATIVE STOCK')
        ->assertJsonPath('rows.0.negative_stock', true);

    expect((int) $response->json('summary.negative_stock_skus'))->toBe(1)
        ->and((int) $response->json('summary.out_of_stock_skus'))->toBe(1);
});

test('stock receipt offsets a negative balance and resets weighted cost to the receipt cost basis', function () {
    $productId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', uomProductPayload([
        'name' => 'Negative Offset Test',
        'sku' => 'SKU-NEG-002',
        'unit_cost' => 10,
        'purchase_uom_cost' => 120,
    ]))->assertCreated()->json('product.id');

    InventoryStock::query()->where('retail_product_id', $productId)->update([
        'on_hand' => -0.5,
        'reserved' => 0,
    ]);

    $supplierId = $this->actingAs($this->user)->postJson('/api/v1/inventory/suppliers', [
        'company_name' => 'Negative Offset Supplier',
        'code_name' => 'Negative Offset',
        'channel' => 'Phone',
        'status' => 'ACTIVE',
        'item_ids' => [$productId],
    ])->assertCreated()->json('supplier.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => now()->toDateString(),
        'supplier_id' => $supplierId,
        'lines' => [[
            'retail_product_id' => $productId,
            'purchase_quantity' => 1,
            'purchase_uom' => 'box',
            'conversion_factor' => 12,
            'purchase_unit_cost' => 240,
            'update_product_conversion' => false,
        ]],
    ])->assertCreated();

    expect((float) InventoryStock::query()->where('retail_product_id', $productId)->value('on_hand'))->toBe(11.5)
        ->and((float) RetailProduct::query()->findOrFail($productId)->unit_cost)->toBe(20.0);
});
