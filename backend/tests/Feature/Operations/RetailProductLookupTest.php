<?php

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\RetailProduct;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
});

function retailLookupFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-RETAIL-LOOKUP-001',
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

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-RETAIL-LOOKUP-001',
        'first_name' => 'Retail',
        'last_name' => 'Customer',
        'mobile_number' => '09173334444',
        'mobile_normalized' => '09173334444',
        'birthday' => '1990-01-01',
        'registration_date' => '2026-09-18',
        'status' => 'ACTIVE',
    ]);

    $product = RetailProduct::query()->create([
        'business_id' => $user->business_id,
        'sku' => 'SKU-009999',
        'name' => 'Scalp Treatment Shampoo',
        'category' => 'Hair Care',
        'unit' => 'bottle',
        'purchase_uom' => 'bottle',
        'purchase_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'retail_available' => true,
        'unit_cost' => 300,
        'selling_price' => 650,
        'reorder_point' => 5,
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

    return compact('user', 'personnel', 'customer', 'product');
}

it('uses product master selling price for booking retail purchases', function () {
    $fx = retailLookupFixture();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings/bootstrap')
        ->assertOk()
        ->assertJsonPath('retail_products.0.id', $fx['product']->id)
        ->assertJsonPath('retail_products.0.selling_price', 650);

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-19',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [],
        'retail_items' => [[
            'retail_product_id' => $fx['product']->id,
            'quantity' => 2,
            'unit_price' => 1,
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
    ])->assertCreated()
        ->assertJsonPath('booking.retail_items.0.retail_product_id', $fx['product']->id)
        ->assertJsonPath('booking.retail_items.0.sku', 'SKU-009999')
        ->assertJsonPath('booking.retail_items.0.item_name', 'Scalp Treatment Shampoo')
        ->assertJsonPath('booking.retail_items.0.unit_price', 650);

    $line = \App\Models\BookingRetailLine::query()->findOrFail($response->json('booking.retail_items.0.id'));
    expect((int) $line->retail_product_id)->toBe((int) $fx['product']->id)
        ->and($line->sku_snapshot)->toBe('SKU-009999')
        ->and((float) $line->unit_price_snapshot)->toBe(650.0);
});

it('uses product master selling price for walk in retail purchases and carries the product into queue', function () {
    $fx = retailLookupFixture();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [],
        'retail_items' => [[
            'retail_product_id' => $fx['product']->id,
            'quantity' => 1,
            'unit_price' => 10,
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
    ])->assertCreated()
        ->assertJsonPath('queue_entry.retail_items.0.retail_product_id', $fx['product']->id)
        ->assertJsonPath('queue_entry.retail_items.0.sku', 'SKU-009999')
        ->assertJsonPath('queue_entry.retail_items.0.unit_price', 650);

    $queueLine = \App\Models\QueueRetailLine::query()->findOrFail($response->json('queue_entry.retail_items.0.id'));
    expect((int) $queueLine->retail_product_id)->toBe((int) $fx['product']->id)
        ->and($queueLine->sku_snapshot)->toBe('SKU-009999')
        ->and((float) $queueLine->unit_price_snapshot)->toBe(650.0);
});
