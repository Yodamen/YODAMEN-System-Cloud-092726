<?php

use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Sale;
use App\Models\Visit;
use Database\Seeders\CheckoutSettlementPermissionSeeder;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\SalesCorePermissionSeeder;
use Database\Seeders\ServicingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
    $this->seed(ServicingPermissionSeeder::class);
    $this->seed(SalesCorePermissionSeeder::class);
    $this->seed(CheckoutSettlementPermissionSeeder::class);
});

it('deducts product master inventory when a booking retail purchase is finalized paid', function () {
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'CASH-INV-BOOKING',
        'display_value' => 'Cash',
        'sort_order' => 10,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $cash);

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-INV-BOOKING',
        'first_name' => 'Retail',
        'last_name' => 'Barber',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-INV-BOOKING',
        'first_name' => 'Inventory',
        'last_name' => 'Customer',
        'mobile_number' => '09179990001',
        'mobile_normalized' => '09179990001',
        'birthday' => '1990-01-01',
        'registration_date' => '2026-09-22',
        'status' => 'ACTIVE',
    ]);

    $product = RetailProduct::query()->create([
        'business_id' => $user->business_id,
        'sku' => 'SKU-INV-BOOKING',
        'name' => 'Booking Styling Wax',
        'category' => 'Styling',
        'unit' => 'pc',
        'purchase_uom' => 'pc',
        'purchase_to_basic_factor' => 1,
        'selling_uom' => 'pc',
        'selling_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'retail_available' => true,
        'unit_cost' => 100,
        'selling_price' => 250,
        'reorder_point' => 1,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    InventoryStock::query()->create([
        'business_id' => $user->business_id,
        'branch_id' => $user->default_branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => 10,
        'reserved' => 0,
    ]);

    $booking = $this->actingAs($user)->postJson('/api/v1/operations/bookings', [
        'customer_id' => $customer->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-22',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [],
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 1,
            'assigned_personnel_id' => $personnel->id,
        ]],
    ])->assertCreated();

    $bookingId = (int) $booking->json('booking.id');
    $this->actingAs($user)->postJson('/api/v1/operations/bookings/'.$bookingId.'/check-in')->assertOk();

    $queueId = (int) \App\Models\QueueEntry::query()->where('booking_id', $bookingId)->value('id');
    $visitResponse = $this->actingAs($user)->postJson('/api/v1/operations/queue/'.$queueId.'/start-servicing')->assertOk();
    $visitId = (int) $visitResponse->json('visit.id');

    $this->actingAs($user)->postJson('/api/v1/operations/servicing/'.$visitId.'/complete')->assertOk();

    $sale = Sale::query()->where('visit_id', $visitId)->firstOrFail()->fresh('items');
    $item = $sale->items->firstOrFail();

    expect((int) $item->retail_product_id)->toBe((int) $product->id)
        ->and((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(10.0)
        ->and(InventoryMovement::query()->where('sale_item_id', $item->id)->where('movement_type', 'RETAIL_SALE')->count())->toBe(0);

    $this->actingAs($user)->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'booking-retail-inventory-paid',
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_method_reference_id' => $cash->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(8.0)
        ->and((float) InventoryMovement::query()->where('sale_item_id', $item->id)->where('movement_type', 'RETAIL_SALE')->value('quantity_change'))->toBe(-2.0);
});

it('blocks checkout instead of silently skipping inventory when a product line has no Product Master link', function () {
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'CASH-INV-LEGACY',
        'display_value' => 'Cash Legacy',
        'sort_order' => 20,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $cash);

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-INV-LEGACY',
        'first_name' => 'Legacy',
        'last_name' => 'Barber',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-INV-LEGACY',
        'first_name' => 'Legacy',
        'last_name' => 'Customer',
        'mobile_number' => '09179990002',
        'mobile_normalized' => '09179990002',
        'birthday' => '1990-01-01',
        'registration_date' => '2026-09-22',
        'status' => 'ACTIVE',
    ]);

    $queue = $this->actingAs($user)->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $customer->id,
        'customer_type' => 'NEW',
        'services' => [],
        'retail_items' => [[
            'item_name' => 'Legacy Unlinked Wax',
            'quantity' => 1,
            'unit_price' => 250,
            'assigned_personnel_id' => $personnel->id,
        ]],
    ])->assertCreated();

    $visitResponse = $this->actingAs($user)
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();
    $visitId = (int) $visitResponse->json('visit.id');

    $this->actingAs($user)->postJson('/api/v1/operations/servicing/'.$visitId.'/complete')->assertOk();

    $sale = Sale::query()->where('visit_id', $visitId)->firstOrFail()->fresh('items');
    $item = $sale->items->firstOrFail();
    expect($item->item_type)->toBe('PRODUCT')->and($item->retail_product_id)->toBeNull();

    $this->actingAs($user)->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'legacy-unlinked-retail-block',
        'advance_allocations' => [],
        'amount_collected_now' => 250,
        'payment_method_reference_id' => $cash->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 250]],
        'allow_remaining_balance' => false,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Legacy Unlinked Wax is a retail sale line without a Product Master link. Checkout was not finalized so inventory is not silently skipped. Correct the transaction and select the Product Master item first.');

    expect($sale->fresh()->financial_finalized_at)->toBeNull()
        ->and(\App\Models\Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'CHECKOUT')->count())->toBe(0);
});
