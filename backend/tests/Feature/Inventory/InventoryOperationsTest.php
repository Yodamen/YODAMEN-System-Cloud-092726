<?php

use App\Models\RetailProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);
    $this->user = foundationUser('ROLE-SA');
});

function inventoryProduct($test, array $overrides = []): RetailProduct
{
    $payload = array_merge([
        'sku' => null,
        'name' => 'Inventory Test Product',
        'category' => 'Retail',
        'brand' => 'YODAMEN',
        'unit' => 'pc',
        'unit_cost' => 120,
        'selling_price' => 300,
        'reorder_point' => 5,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ], $overrides);
    $id = $test->actingAs($test->user)->postJson('/api/v1/inventory/products', $payload)->assertCreated()->json('product.id');
    return RetailProduct::query()->findOrFail($id);
}

function inventoryOperationsPostAdjustment($test, RetailProduct $product, float $quantityChange, string $reason): void
{
    $requestId = (int) $test->actingAs($test->user)
        ->postJson('/api/v1/inventory/products/'.$product->id.'/adjust-stock', [
            'branch_id' => $test->user->default_branch_id,
            'quantity_change' => $quantityChange,
            'reason_reference_id' => $test->workflowReasonId('OTHER'),
            'reason' => $reason,
        ])
        ->assertCreated()
        ->assertJsonPath('request.status', 'PENDING')
        ->json('request.id');

    $test->actingAs($test->user)
        ->postJson('/api/v1/inventory/adjustment-requests/'.$requestId.'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');
}

test('inventory dashboard and movement ledger expose current branch stock', function () {
    $product = inventoryProduct($this);
    inventoryOperationsPostAdjustment($this, $product, 8, 'Opening stock');

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard')
        ->assertOk()
        ->assertJsonPath('rows.0.product_id', $product->id)
        ->assertJsonPath('rows.0.available', 8)
        ->assertJsonPath('rows.0.low_stock', false);

    $this->actingAs($this->user)->getJson('/api/v1/inventory/movements?movement_type=ADJUSTMENT')
        ->assertOk()
        ->assertJsonPath('rows.0.product.id', $product->id)
        ->assertJsonPath('rows.0.quantity_change', 8);
});

test('reorder monitoring only includes stock at or below reorder point', function () {
    $product = inventoryProduct($this, ['reorder_point' => 5]);
    inventoryOperationsPostAdjustment($this, $product, 3, 'Low opening stock');

    $this->actingAs($this->user)->getJson('/api/v1/inventory/reorder')
        ->assertOk()
        ->assertJsonPath('rows.0.product_id', $product->id)
        ->assertJsonPath('rows.0.available', 3)
        ->assertJsonPath('rows.0.shortfall_to_reorder_point', 2);
});

test('potential retail value only includes inventory items enabled for retail', function () {
    $retail = inventoryProduct($this, ['sku' => 'SKU-RETAIL-001', 'name' => 'Retail Item', 'selling_price' => 300, 'retail_available' => true]);
    $internal = inventoryProduct($this, ['sku' => 'SKU-INTERNAL-001', 'name' => 'Service Use Item', 'selling_price' => 999, 'retail_available' => false]);

    foreach ([$retail, $internal] as $product) {
        inventoryOperationsPostAdjustment($this, $product, 2, 'Opening stock');
    }

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard')
        ->assertOk()
        ->assertJsonPath('summary.retail_value', 600);
});

test('inventory dashboard includes active inventory items even before stock exists', function () {
    $product = inventoryProduct($this, [
        'sku' => 'SKU-ZERO-001',
        'name' => 'Zero Stock Item',
        'retail_available' => false,
        'unit_cost' => 75,
    ]);

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard?product_id='.$product->id)
        ->assertOk()
        ->assertJsonPath('rows.0.product_id', $product->id)
        ->assertJsonPath('rows.0.on_hand', 0)
        ->assertJsonPath('rows.0.available', 0)
        ->assertJsonPath('rows.0.sellable', false)
        ->assertJsonPath('rows.0.weighted_average_cost', 75);
});

test('dashboard exposes weighted average cost and item movement drilldown', function () {
    $product = inventoryProduct($this, [
        'sku' => 'SKU-WAC-001',
        'name' => 'Weighted Cost Item',
        'unit_cost' => 100,
        'selling_price' => 300,
        'retail_available' => true,
    ]);

    $supplierId = $this->actingAs($this->user)->postJson('/api/v1/inventory/suppliers', [
        'company_name' => 'Weighted Cost Supplier',
        'code_name' => 'WAC Supplier',
        'channel' => 'Phone',
        'status' => 'ACTIVE',
        'item_ids' => [$product->id],
    ])->assertCreated()->json('supplier.id');

    foreach ([[10, 100], [10, 200]] as [$qty, $cost]) {
        $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
            'branch_id' => $this->user->default_branch_id,
            'received_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'lines' => [[
                'retail_product_id' => $product->id,
                'purchase_quantity' => $qty,
                'purchase_uom' => 'pc',
                'conversion_factor' => 1,
                'purchase_unit_cost' => $cost,
            ]],
        ])->assertCreated();
    }

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard?product_id='.$product->id)
        ->assertOk()
        ->assertJsonPath('rows.0.on_hand', 20)
        ->assertJsonPath('rows.0.sellable', true)
        ->assertJsonPath('rows.0.weighted_average_cost', 150)
        ->assertJsonPath('rows.0.cost_value', 3000)
        ->assertJsonPath('rows.0.retail_value', 6000);

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard/products/'.$product->id.'/movements?branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('product.weighted_average_cost', 150)
        ->assertJsonPath('product.on_hand', 20)
        ->assertJsonPath('rows.0.movement_type', 'STOCK_RECEIVED')
        ->assertJsonPath('rows.0.unit_cost_snapshot', 200);
});


test('inventory dashboard distinguishes out of stock and inactive skus', function () {
    $out = inventoryProduct($this, [
        'sku' => 'SKU-OUT-001',
        'name' => 'Out of Stock Item',
        'reorder_point' => 5,
    ]);
    $inactive = inventoryProduct($this, [
        'sku' => 'SKU-INACTIVE-001',
        'name' => 'Inactive Item',
        'status' => 'INACTIVE',
    ]);

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard?product_id='.$out->id)
        ->assertOk()
        ->assertJsonPath('summary.out_of_stock_skus', 1)
        ->assertJsonPath('rows.0.status', 'OUT OF STOCK');

    $this->actingAs($this->user)->getJson('/api/v1/inventory/dashboard?product_id='.$inactive->id)
        ->assertOk()
        ->assertJsonPath('summary.inactive_skus', 1)
        ->assertJsonPath('rows.0.status', 'INACTIVE');
});
