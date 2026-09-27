<?php

use App\Models\CommissionEarning;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Payment;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 13:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);

    $this->user = foundationUser('ROLE-SA');
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $this->cash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'CASH-POS-62',
        'display_value' => 'Cash',
        'sort_order' => 10,
        'active' => true,
    ]);
    $this->gcash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'GCASH-POS-62',
        'display_value' => 'GCash',
        'sort_order' => 20,
        'active' => true,
    ]);

    mapCustomerPaymentMethod($this->user, $this->cash);
    mapCustomerPaymentMethod($this->user, $this->gcash, 'POS_CLEARING');

    $this->personnel = Personnel::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'EMP-POS-062',
        'first_name' => 'Retail',
        'last_name' => 'Staff',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);

    $this->customer = Customer::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'CUS-POS-062',
        'first_name' => 'Retail',
        'last_name' => 'Buyer',
        'mobile_number' => '09170000062',
        'mobile_normalized' => '09170000062',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    $productResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'sku' => 'SKU-POS-062',
        'barcode' => '480000000062',
        'name' => 'Retail POS Pomade',
        'category' => 'Styling',
        'brand' => 'YODAMEN',
        'unit' => 'pc',
        'unit_cost' => 200,
        'selling_price' => 500,
        'reorder_point' => 2,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'notes' => null,
    ])->assertCreated();

    $this->product = RetailProduct::query()->findOrFail($productResponse->json('product.id'));

    // Test setup seeds opening stock directly. The public Adjust Stock API now
    // creates a pending maker-checker request (201) and intentionally does not
    // post inventory until an authorized approver approves it.
    InventoryStock::query()->updateOrCreate(
        [
            'business_id' => $this->user->business_id,
            'branch_id' => $this->user->default_branch_id,
            'retail_product_id' => $this->product->id,
        ],
        [
            'on_hand' => 10,
            'reserved' => 0,
        ],
    );
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function retailPosCommissionRulePayload($personnelId, $productId): array
{
    return [
        'rule_name' => 'Specific POS Product 10%',
        'commission_scope' => 'RETAIL',
        'branch_id' => null,
        'applies_to' => 'EMPLOYEE',
        'personnel_id' => $personnelId,
        'position_reference_id' => null,
        'service_id' => null,
        'service_category_reference_id' => null,
        'retail_product_id' => $productId,
        'retail_category' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2026-09-18',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
        'notes' => null,
    ];
}

function retailPosDiscountPayload(): array
{
    return [
        'rule_name' => 'Retail POS Save 10%',
        'discount_code' => 'POS10',
        'branch_id' => null,
        'applies_to' => 'ALL',
        'customer_id' => null,
        'customer_type' => null,
        'min_lifetime_value' => null,
        'max_lifetime_value' => null,
        'last_visit_operator' => null,
        'last_visit_days_min' => null,
        'last_visit_days_max' => null,
        'item_scope' => 'PRODUCT',
        'service_id' => null,
        'service_category_reference_id' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'effective_from' => '2026-09-18',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
        'notes' => null,
    ];
}

test('retail pos preview calculates discounted total before split payment finalization', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', retailPosDiscountPayload())
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson('/api/v1/sales/retail-pos/preview', [
            'branch_id' => $this->user->default_branch_id,
            'customer_id' => $this->customer->id,
            'discount_code' => 'POS10',
            'items' => [[
                'retail_product_id' => $this->product->id,
                'quantity' => 2,
                'assigned_personnel_id' => null,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('preview.gross_amount', 1000)
        ->assertJsonPath('preview.discount_amount', 100)
        ->assertJsonPath('preview.net_amount', 900)
        ->assertJsonPath('preview.discount_code', 'POS10');

    expect(Sale::query()->where('sale_channel', 'RETAIL_POS')->count())->toBe(0)
        ->and((int) InventoryStock::query()->where('retail_product_id', $this->product->id)->value('on_hand'))->toBe(10);
});

test('retail pos finalization deducts stock accepts split payment and posts assigned staff commission', function () {
    $ruleId = $this->actingAs($this->user)
        ->postJson('/api/v1/admin/commission-rules', retailPosCommissionRulePayload($this->personnel->id, $this->product->id))
        ->assertCreated()
        ->assertJsonPath('rule.retail_product.id', $this->product->id)
        ->json('rule.id');

    $this->actingAs($this->user)
        ->postJson('/api/v1/admin/discount-rules', retailPosDiscountPayload())
        ->assertCreated();

    $proofToken = $this->supportingDocumentToken($this->user, 'sales', 'retail-gcash-proof.pdf');

    $saleId = $this->actingAs($this->user)
        ->postJson('/api/v1/sales/retail-pos', [
            'attachment_tokens' => [$proofToken],
            'branch_id' => $this->user->default_branch_id,
            'customer_id' => null,
            'discount_code' => 'POS10',
            'notes' => 'Retail-only POS transaction',
            'idempotency_key' => 'retail-pos-62-finalize-1',
            'items' => [[
                'retail_product_id' => $this->product->id,
                'quantity' => 2,
                'assigned_personnel_id' => $this->personnel->id,
            ]],
            'payments' => [
                ['payment_method_reference_id' => $this->cash->id, 'amount' => 400, 'reference_no' => null],
                ['payment_method_reference_id' => $this->gcash->id, 'amount' => 500, 'reference_no' => 'GC-POS-62'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('sale.status', 'PAID')
        ->assertJsonPath('sale.customer', null)
        ->assertJsonPath('sale.gross_amount', 1000)
        ->assertJsonPath('sale.discount_amount', 100)
        ->assertJsonPath('sale.net_amount', 900)
        ->assertJsonPath('sale.items.0.sku', 'SKU-POS-062')
        ->assertJsonPath('sale.items.0.assigned_staff.id', $this->personnel->id)
        ->json('sale.id');

    $sale = Sale::query()->findOrFail($saleId);
    $earning = CommissionEarning::query()->where('sale_id', $saleId)->where('earning_type', 'RETAIL')->firstOrFail();

    $this->actingAs($this->user)
        ->getJson('/api/v1/supporting-documents?entity_type=SALE_CHECKOUT&entity_id='.$saleId)
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.name', 'retail-gcash-proof.pdf');

    expect($sale->booking_id)->toBeNull()
        ->and($sale->visit_id)->toBeNull()
        ->and((int) InventoryStock::query()->where('retail_product_id', $this->product->id)->value('on_hand'))->toBe(8)
        ->and((float) InventoryMovement::query()->where('sale_id', $saleId)->where('movement_type', 'RETAIL_SALE')->value('quantity_change'))->toBe(-2.0)
        ->and(Payment::query()->where('sale_id', $saleId)->where('status', 'RECEIVED')->count())->toBe(2)
        ->and((float) Payment::query()->where('sale_id', $saleId)->sum('amount'))->toBe(900.0)
        ->and($earning->commission_rule_id)->toBe($ruleId)
        ->and((float) $earning->basis_amount)->toBe(900.0)
        ->and((float) $earning->commission_amount)->toBe(90.0)
        ->and($earning->personnel_id)->toBe($this->personnel->id)
        ->and($earning->status)->toBe('OPEN');


    $paidLines = $this->actingAs($this->user)
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID')
        ->assertOk();

    $retailLine = collect($paidLines->json('lines'))->firstWhere('sale_id', $saleId);
    expect($retailLine)->not->toBeNull()
        ->and((float) ($retailLine['payment_amount'] ?? 0))->toBe(900.0)
        ->and(count($retailLine['payment_details'] ?? []))->toBe(2);
});


test('retail pos requires payment proof for non-cash checkout', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/sales/retail-pos', [
            'branch_id' => $this->user->default_branch_id,
            'customer_id' => null,
            'notes' => 'GCash without proof must be blocked',
            'idempotency_key' => 'retail-pos-proof-required',
            'items' => [[
                'retail_product_id' => $this->product->id,
                'quantity' => 1,
                'assigned_personnel_id' => $this->personnel->id,
            ]],
            'payments' => [[
                'payment_method_reference_id' => $this->gcash->id,
                'amount' => 500,
                'reference_no' => 'GC-NO-PROOF',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Retail checkout payment proof is required for non-cash payments such as GCash, bank transfer, e-wallet or card. Cash payments are exempt.');

    expect(Sale::query()->where('sale_channel', 'RETAIL_POS')->count())->toBe(0)
        ->and((int) InventoryStock::query()->where('retail_product_id', $this->product->id)->value('on_hand'))->toBe(10);
});

test('retail pos rejects split payment totals that do not equal the checkout total', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/sales/retail-pos', [
            'branch_id' => $this->user->default_branch_id,
            'customer_id' => null,
            'discount_code' => null,
            'notes' => null,
            'idempotency_key' => 'retail-pos-62-bad-split',
            'items' => [[
                'retail_product_id' => $this->product->id,
                'quantity' => 1,
                'assigned_personnel_id' => $this->personnel->id,
            ]],
            'payments' => [[
                'payment_method_reference_id' => $this->cash->id,
                'amount' => 499,
                'reference_no' => null,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Split Payment Total must equal the Total Checkout Amount exactly.');

    expect(Sale::query()->where('sale_channel', 'RETAIL_POS')->count())->toBe(0)
        ->and((int) InventoryStock::query()->where('retail_product_id', $this->product->id)->value('on_hand'))->toBe(10);
});

test('retail pos converts selling uom quantity to basic stock quantity', function () {
    $this->product->update([
        'unit' => 'g',
        'selling_uom' => 'jar',
        'selling_to_basic_factor' => 5,
    ]);
    InventoryStock::query()->where('retail_product_id', $this->product->id)->update(['on_hand' => 10, 'reserved' => 0]);

    $saleId = $this->actingAs($this->user)
        ->postJson('/api/v1/sales/retail-pos', [
            'branch_id' => $this->user->default_branch_id,
            'customer_id' => null,
            'discount_code' => null,
            'notes' => null,
            'idempotency_key' => 'retail-pos-uom-conversion-1',
            'items' => [[
                'retail_product_id' => $this->product->id,
                'quantity' => 1,
                'assigned_personnel_id' => $this->personnel->id,
            ]],
            'payments' => [[
                'payment_method_reference_id' => $this->cash->id,
                'amount' => 500,
                'reference_no' => null,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('sale.items.0.quantity', 1)
        ->assertJsonPath('sale.items.0.selling_uom', 'jar')
        ->assertJsonPath('sale.items.0.basic_quantity', 5)
        ->json('sale.id');

    expect((int) InventoryStock::query()->where('retail_product_id', $this->product->id)->value('on_hand'))->toBe(5)
        ->and((int) InventoryMovement::query()->where('sale_id', $saleId)->where('movement_type', 'RETAIL_SALE')->value('quantity_change'))->toBe(-5)
        ->and((float) Sale::query()->findOrFail($saleId)->items()->firstOrFail()->basic_quantity_snapshot)->toBe(5.0);
});
