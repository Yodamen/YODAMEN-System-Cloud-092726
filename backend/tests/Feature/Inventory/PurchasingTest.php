<?php

use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\RetailProduct;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->admin=foundationUser('ROLE-SA');
    $this->branch=Branch::query()->where('business_id',$this->admin->business_id)->firstOrFail();
    $this->supplier=Supplier::query()->create([
        'business_id'=>$this->admin->business_id,'code'=>'PO-SUP-001',
        'company_name'=>'Purchase Test Supplier','status'=>'ACTIVE','created_by'=>$this->admin->id,
    ]);
    $this->product=RetailProduct::query()->create([
        'business_id'=>$this->admin->business_id,'sku'=>'PO-TEST-001','name'=>'PO Test Item',
        'category'=>'Supplies','unit'=>'piece','purchase_uom'=>'case','purchase_to_basic_factor'=>12,
        'stock_type'=>'INVENTORY','status'=>'ACTIVE','unit_cost'=>5,'selling_price'=>20,
        'created_by'=>$this->admin->id,
    ]);
    $this->supplier->items()->attach($this->product->id);
});

function purchasingPayload($test): array {
    return [
        'branch_id'=>$test->branch->id,'supplier_id'=>$test->supplier->id,
        'order_date'=>'2026-09-25','expected_delivery_date'=>'2026-09-30',
        'lines'=>[['retail_product_id'=>$test->product->id,'purchase_quantity'=>10,
            'purchase_uom'=>'case','conversion_factor'=>12,'purchase_unit_cost'=>60]],
    ];
}

test('purchase order remains non-posting until receipt and requires approval', function () {
    $created=$this->actingAs($this->admin)->postJson('/api/v1/purchasing/orders',purchasingPayload($this))
        ->assertCreated()->assertJsonPath('order.status','DRAFT')->json('order');
    expect(PurchaseOrder::query()->count())->toBe(1)
        ->and(\App\Models\InventoryReceipt::query()->count())->toBe(0)
        ->and(\App\Models\InventoryMovement::query()->count())->toBe(0);
    $id=$created['id'];
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/receive",[
        'received_date'=>'2026-09-26','attachment_tokens'=>[], 'lines'=>[['purchase_order_line_id'=>$created['lines'][0]['id'],'purchase_quantity'=>1]],
    ])->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/submit")
        ->assertOk()->assertJsonPath('order.status','SUBMITTED');
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/approve")
        ->assertOk()->assertJsonPath('order.status','APPROVED');
    expect(\App\Models\InventoryReceipt::query()->count())->toBe(0);
});

test('draft can be cancelled but cannot be approved directly', function () {
    $id=$this->actingAs($this->admin)->postJson('/api/v1/purchasing/orders',purchasingPayload($this))->assertCreated()->json('order.id');
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/cancel",['reason'=>'Supplier order cancelled.'])
        ->assertOk()->assertJsonPath('order.status','CANCELLED');
    $this->actingAs($this->admin)->putJson("/api/v1/purchasing/orders/{$id}",purchasingPayload($this))->assertStatus(422);
});


test('approved purchase order supports partial receiving and blocks excess quantity', function () {
    $created=$this->actingAs($this->admin)->postJson('/api/v1/purchasing/orders',purchasingPayload($this))
        ->assertCreated()->json('order');
    $id=$created['id'];
    $lineId=$created['lines'][0]['id'];
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/submit")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/receive",[
        'received_date'=>'2026-09-26',
        'attachment_tokens'=>[$this->supportingDocumentToken($this->admin,'stock-received')],
        'lines'=>[['purchase_order_line_id'=>$lineId,'purchase_quantity'=>4]],
    ])->assertCreated()->assertJsonPath('order.receiving_status','PARTIALLY_RECEIVED')
      ->assertJsonPath('order.lines.0.remaining_quantity',6);
    $orderResponse=$this->actingAs($this->admin)->getJson("/api/v1/purchasing/orders/{$id}")
        ->assertOk()->assertJsonPath('order.receipts.0.received_by',$this->admin->display_name ?: $this->admin->name)
        ->assertJsonPath('order.receipts.0.lines.0.product_name', 'PO Test Item');
    $this->actingAs($this->admin)->getJson('/api/v1/accounting/accounts-payable?source=INVENTORY_RECEIPT&as_of=2026-09-26')
        ->assertOk()
        ->assertJsonPath('rows.0.items.0.name', 'PO Test Item')
        ->assertJsonPath('rows.0.items.0.purchase_quantity', 4);
    expect(\App\Models\InventoryReceipt::query()->where('purchase_order_id',$id)->count())->toBe(1)
        ->and(\App\Models\InventoryMovement::query()->where('retail_product_id',$this->product->id)->where('movement_type','STOCK_RECEIVED')->count())->toBe(1);
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/receive",[
        'received_date'=>'2026-09-26',
        'attachment_tokens'=>[$this->supportingDocumentToken($this->admin,'stock-received')],
        'lines'=>[['purchase_order_line_id'=>$lineId,'purchase_quantity'=>7]],
    ])->assertStatus(422)->assertJsonPath('message','Receiving exceeds the approved remaining Purchase Order quantity.');
    expect(\App\Models\InventoryReceipt::query()->where('purchase_order_id',$id)->count())->toBe(1);
});

test('reception is forbidden from accessing purchasing', function () {
    $reception=foundationUser('ROLE-REC');
    $this->actingAs($reception)->getJson('/api/v1/purchasing/orders')->assertForbidden();
    $this->actingAs($reception)->postJson('/api/v1/purchasing/orders',purchasingPayload($this))->assertForbidden();
});


test('same-day purchase order delivery is accepted and fully reversed receipt changes PO status', function () {
    $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->toDateString();
    $payload = purchasingPayload($this);
    $payload['order_date'] = $today;
    $payload['expected_delivery_date'] = $today;
    $created = $this->actingAs($this->admin)->postJson('/api/v1/purchasing/orders', $payload)
        ->assertCreated()->json('order');
    $id = $created['id'];
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/submit")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/receive", [
        'received_date' => $today,
        'attachment_tokens' => [$this->supportingDocumentToken($this->admin, 'stock-received')],
        'lines' => [['purchase_order_line_id' => $created['lines'][0]['id'], 'purchase_quantity' => 1]],
    ])->assertCreated()->assertJsonPath('order.status', 'APPROVED');
    // Underlying stock reversal remains an approval-controlled action.
    // A fully reversed receipt status must also be reflected in PO status.
    $receipt = \App\Models\InventoryReceipt::query()->where('purchase_order_id', $id)->firstOrFail();
    $receipt->update(['status' => 'REVERSED']);
    $this->actingAs($this->admin)->getJson("/api/v1/purchasing/orders/{$id}")
        ->assertOk()->assertJsonPath('order.status', 'REVERSED');
    $this->actingAs($this->admin)->postJson("/api/v1/purchasing/orders/{$id}/receive", [
        'received_date' => $today, 'attachment_tokens' => [],
        'lines' => [['purchase_order_line_id' => $created['lines'][0]['id'], 'purchase_quantity' => 1]],
    ])->assertStatus(422);
});
