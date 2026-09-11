<?php

use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Manila'));
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('single session service forces one session and creates initial price', function () {
    $user = foundationUser('ROLE-SA');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/admin/services', [
            'name' => 'Classic Haircut',
            'category_code' => 'HAIRCUT',
            'service_count_code' => 'SINGLE_SESSION',
            'number_of_sessions' => 7,
            'duration_minutes' => 45,
            'description' => 'Standard haircut',
            'status_code' => 'ACTIVE',
            'initial_price' => 350,
            'effective_from' => '2026-09-01',
        ])
        ->assertCreated()
        ->assertJsonPath('service.number_of_sessions', 1)
        ->assertJsonPath('service.current_price.amount', 350);

    expect(Service::query()->where('name', 'Classic Haircut')->count())->toBe(1);
});

test('price history closes the previous period when a later price is added', function () {
    $user = foundationUser('ROLE-SA');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/admin/services', [
            'name' => 'Signature Cut',
            'category_code' => 'SIGNATURE',
            'service_count_code' => 'SINGLE_SESSION',
            'number_of_sessions' => 1,
            'duration_minutes' => 60,
            'description' => null,
            'status_code' => 'ACTIVE',
            'initial_price' => 500,
            'effective_from' => '2026-09-01',
        ])
        ->assertCreated();

    $serviceId = $response->json('service.id');

    $this->actingAs($user)
        ->postJson('/api/v1/admin/services/'.$serviceId.'/prices', [
            'price' => 550,
            'effective_from' => '2026-10-01',
        ])
        ->assertCreated();

    $service = Service::query()->findOrFail($serviceId);
    $prices = $service->prices()->orderBy('effective_from')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices[0]->effective_until->toDateString())->toBe('2026-09-30')
        ->and($prices[1]->status)->toBe('SCHEDULED');
});
