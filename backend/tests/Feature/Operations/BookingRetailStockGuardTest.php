<?php

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

it('blocks a retail product from being saved to a booking when available stock is insufficient', function () {
    $user = foundationUser();

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-STOCK-GUARD-001',
        'first_name' => 'Stock',
        'last_name' => 'Guard',
        'mobile_number' => '09170001111',
        'mobile_normalized' => '09170001111',
        'birthday' => '1990-01-01',
        'registration_date' => '2026-09-24',
        'status' => 'ACTIVE',
    ]);

    $product = RetailProduct::query()->create([
        'business_id' => $user->business_id,
        'sku' => 'SKU-STOCK-GUARD-001',
        'name' => 'Booking Stock Guard Wax',
        'category' => 'Retail',
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
        'on_hand' => 1,
        'reserved' => 0,
    ]);

    $payload = [
        'customer_id' => $customer->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-24',
        'booking_time' => '14:00',
        'booking_source' => 'RESERVATION',
        'services' => [],
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 250,
            'assigned_personnel_id' => null,
        ]],
    ];

    $this->actingAs($user)
        ->postJson('/api/v1/operations/bookings', $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Booking Stock Guard Wax has insufficient stock for this Booking. Available: 1 pc.');

    expect(\App\Models\Booking::query()->count())->toBe(0);

    $payload['retail_items'][0]['quantity'] = 1;
    $this->actingAs($user)
        ->postJson('/api/v1/operations/bookings', $payload)
        ->assertCreated();
});
