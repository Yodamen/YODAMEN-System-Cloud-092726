<?php

use App\Models\InventoryMovement;
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

function retailProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'SKU-TEST-001',
        'barcode' => '480000000001',
        'name' => 'Test Styling Wax',
        'category' => 'Styling',
        'brand' => 'YODAMEN',
        'unit' => 'pc',
        'unit_cost' => 200,
        'selling_price' => 500,
        'reorder_point' => 3,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'notes' => null,
    ], $overrides);
}

function retailProductSubmitAdjustment($test, int $productId, float $quantityChange, string $reason): int
{
    return (int) $test->actingAs($test->user)
        ->postJson('/api/v1/inventory/products/'.$productId.'/adjust-stock', [
            'branch_id' => $test->user->default_branch_id,
            'quantity_change' => $quantityChange,
            'reason_reference_id' => $test->workflowReasonId('OTHER'),
            'reason' => $reason,
        ])
        ->assertCreated()
        ->assertJsonPath('request.status', 'PENDING')
        ->json('request.id');
}

function retailProductApproveAdjustment($test, int $requestId): void
{
    $test->actingAs($test->user)
        ->postJson('/api/v1/inventory/adjustment-requests/'.$requestId.'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');
}

test('product sku master can create filter and adjust branch stock', function () {
    $productId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', retailProductPayload())
        ->assertCreated()
        ->assertJsonPath('product.sku', 'SKU-TEST-001')
        ->json('product.id');

    $adjustmentId = retailProductSubmitAdjustment($this, $productId, 12, 'Opening stock for POS testing');
    retailProductApproveAdjustment($this, $adjustmentId);

    $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/products?search=SKU-TEST-001&category=Styling&status=ACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $productId)
        ->assertJsonPath('products.0.on_hand', 12)
        ->assertJsonPath('products.0.available', 12)
        ->assertJsonPath('products.0.low_stock', false);

    expect(InventoryMovement::query()->where('retail_product_id', $productId)->where('movement_type', 'ADJUSTMENT')->count())->toBe(1);
});

test('sku is unique per business and stock cannot be adjusted below zero', function () {
    $productId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', retailProductPayload())
        ->assertCreated()
        ->json('product.id');

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', retailProductPayload(['name' => 'Duplicate SKU Product']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'SKU is already in use.');

    $adjustmentId = retailProductSubmitAdjustment($this, $productId, -1, 'Invalid negative opening stock');

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/adjustment-requests/'.$adjustmentId.'/approve')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Manual stock deductions cannot create or deepen a negative On Hand balance.');

    $stock = RetailProduct::query()->findOrFail($productId)->stocks()->first();
    expect((float) ($stock?->on_hand ?? 0))->toBe(0.0);
});

test('product masterlist auto generates sku and exposes ecommerce reference options', function () {
    $payload = retailProductPayload([
        'sku' => null,
        'category' => 'Styling',
        'brand' => null,
        'unit' => 'pc',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', $payload)
        ->assertCreated();

    $productId = (int) $response->json('product.id');
    $response->assertJsonPath('product.sku', 'SKU-'.str_pad((string) $productId, 6, '0', STR_PAD_LEFT));

    $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/products')
        ->assertOk()
        ->assertJsonFragment(['value' => 'Styling'])
        ->assertJsonFragment(['value' => 'pc']);
});


test('retail selling price supports effectivity dates and retail commission filters', function () {
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    $productId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', retailProductPayload([
            'sku' => 'SKU-PRICE-001',
            'retail_available' => true,
            'commission_eligible' => true,
            'selling_price' => 500,
            'selling_price_effective_from' => $today,
        ]))
        ->assertCreated()
        ->json('product.id');

    $this->actingAs($this->user)
        ->putJson('/api/v1/inventory/products/'.$productId, retailProductPayload([
            'sku' => 'SKU-PRICE-001',
            'retail_available' => true,
            'commission_eligible' => true,
            'selling_price' => 650,
            'selling_price_effective_from' => $tomorrow,
        ]))
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/products?product_id='.$productId.'&retail=YES&commission=YES')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.selling_price', 500)
        ->assertJsonPath('products.0.future_selling_price.amount', 650)
        ->assertJsonPath('products.0.future_selling_price.effective_from', $tomorrow)
        ->assertJsonPath('products.0.selling_price_history.0.amount', 650)
        ->assertJsonPath('products.0.selling_price_history.0.effective_from', $tomorrow)
        ->assertJsonPath('products.0.selling_price_history.1.amount', 500)
        ->assertJsonPath('products.0.selling_price_history.1.effective_from', $today);

    expect(RetailProduct::query()->findOrFail($productId)->sellingPriceForDate($tomorrow))->toBe(650.0);
});

test('non approving inventory staff submits new items for manager approval before dashboard activation', function () {
    $staff = foundationUser('ROLE-REC');
    $permissionIds = \App\Models\Permission::query()
        ->where('module', 'inventory-master')
        ->whereIn('action', ['VIEW', 'CREATE', 'EDIT'])
        ->pluck('id')
        ->all();
    $staff->role->permissions()->syncWithoutDetaching($permissionIds);

    $response = $this->actingAs($staff)
        ->postJson('/api/v1/inventory/products', retailProductPayload([
            'sku' => 'SKU-PENDING-001',
            'name' => 'Pending Approval Wax',
        ]))
        ->assertCreated()
        ->assertJsonPath('product.status', 'PENDING_APPROVAL');

    $productId = (int) $response->json('product.id');

    $this->actingAs($staff)
        ->getJson('/api/v1/inventory/products?status=PENDING_APPROVAL')
        ->assertOk()
        ->assertJsonPath('products.0.id', $productId)
        ->assertJsonPath('products.0.display_status', 'PENDING_APPROVAL')
        ->assertJsonPath('products.0.approval_pending', true);

    $dashboardRows = $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/dashboard')
        ->assertOk()
        ->json('rows');
    expect(collect($dashboardRows)->pluck('product_id')->contains($productId))->toBeFalse();

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products/'.$productId.'/approve')
        ->assertOk()
        ->assertJsonPath('product.status', 'ACTIVE');

    $dashboardRows = $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/dashboard')
        ->assertOk()
        ->json('rows');
    expect(collect($dashboardRows)->pluck('product_id')->contains($productId))->toBeTrue();
});

test('active inventory item cannot be deactivated while stock balance remains', function () {
    $productId = $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/products', retailProductPayload([
            'sku' => 'SKU-DEACTIVATE-001',
            'name' => 'Stocked Item',
        ]))
        ->assertCreated()
        ->json('product.id');

    $adjustmentId = retailProductSubmitAdjustment($this, $productId, 1, 'Opening stock');
    retailProductApproveAdjustment($this, $adjustmentId);

    $this->actingAs($this->user)
        ->patchJson('/api/v1/inventory/products/'.$productId.'/toggle')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This item cannot be deactivated while stock remains on hand or reserved.');
});
