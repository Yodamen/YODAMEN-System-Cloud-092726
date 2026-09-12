<?php

use App\Models\Customer;
use App\Models\ReferenceGroup;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

it('creates customer codes and blocks duplicate normalized mobile numbers', function () {
    $user = foundationUser();

    $payload = [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '+63 917 123 4567',
        'email' => 'juan@example.com',
        'birthday' => '1990-01-10',
        'gender_code' => 'MALE',
        'address' => 'San Jose City',
        'lead_source_code' => 'REFERRAL',
        'registration_date' => '2026-09-12',
        'notes' => 'Test customer',
    ];

    $this->actingAs($user)->postJson('/api/v1/operations/customers', $payload)
        ->assertCreated()
        ->assertJsonPath('customer.code', 'CUS-0926-000001');

    $this->actingAs($user)->postJson('/api/v1/operations/customers', array_merge($payload, [
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'birthday' => '1995-05-15',
        'email' => 'maria@example.com',
        'mobile_number' => '09171234567',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Possible duplicate customer: this mobile number is already registered to another customer.');

    expect(Customer::count())->toBe(1);
});

it('blocks the same normalized name and birthday even when the mobile number differs', function () {
    $user = foundationUser();

    $payload = [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09170000001',
        'email' => 'juan@example.com',
        'birthday' => '1990-01-10',
        'gender_code' => 'MALE',
        'address' => 'San Jose City',
        'lead_source_code' => 'REFERRAL',
        'registration_date' => '2026-09-12',
        'notes' => null,
    ];

    $this->actingAs($user)->postJson('/api/v1/operations/customers', $payload)
        ->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/operations/customers', array_merge($payload, [
        'first_name' => '  JUAN  ',
        'last_name' => 'DELA   CRUZ',
        'mobile_number' => '09170000002',
        'email' => 'juan2@example.com',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Possible duplicate customer: the same name and birthday are already registered.');

    expect(Customer::count())->toBe(1);
});

it('allows the same name when the birthday and mobile number are different', function () {
    $user = foundationUser();

    $base = [
        'first_name' => 'Alex',
        'last_name' => 'Reyes',
        'mobile_number' => '09170000011',
        'birthday' => '1990-01-10',
        'registration_date' => '2026-09-12',
    ];

    $this->actingAs($user)->postJson('/api/v1/operations/customers', $base)
        ->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/operations/customers', array_merge($base, [
        'mobile_number' => '09170000012',
        'birthday' => '1991-01-10',
    ]))->assertCreated();

    expect(Customer::count())->toBe(2);
});


it('allows customer registration with only first and last name', function () {
    $user = foundationUser();

    $response = $this->actingAs($user)->postJson('/api/v1/operations/customers', [
        'first_name' => 'Minimal',
        'last_name' => 'Customer',
    ]);

    $response->assertCreated()
        ->assertJsonPath('customer.first_name', 'Minimal')
        ->assertJsonPath('customer.last_name', 'Customer')
        ->assertJsonPath('customer.mobile_number', null)
        ->assertJsonPath('customer.birthday', null);

    expect(Customer::query()->where('first_name', 'Minimal')->where('last_name', 'Customer')->exists())->toBeTrue();
});

it('toggles customer status without deleting the customer', function () {
    $user = foundationUser();
    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-0926-000999',
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'mobile_number' => '09170000000',
        'mobile_normalized' => '09170000000',
        'birthday' => '1990-01-01',
        'registration_date' => '2026-09-12',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($user)->patchJson("/api/v1/operations/customers/{$customer->id}/toggle")
        ->assertOk()
        ->assertJsonPath('customer.status', 'INACTIVE');

    expect($customer->fresh()->status)->toBe('INACTIVE');
});
