<?php

use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->app['env'] = 'local';
    Storage::fake('local');
});

it('restricts Development Reset API to active Super Admin accounts', function () {
    $manager = foundationUser('ROLE-MGR');

    $this->actingAs($manager)
        ->getJson('/api/v1/admin/development-reset')
        ->assertForbidden();
});

it('arms and executes the test-customer reset through the Super Admin API', function () {
    $superAdmin = foundationUser('ROLE-SA');

    $this->actingAs($superAdmin)
        ->getJson('/api/v1/admin/development-reset')
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('environment', 'local');

    $armed = $this->actingAs($superAdmin)
        ->postJson('/api/v1/admin/development-reset/arm', [
            'mode' => 'TEST_ONLY',
            'access_code' => '123456',
            'confirmation_phrase' => 'RESET TEST CUSTOMERS',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'TEST_ONLY');

    $token = $armed->json('arm_token');
    expect($token)->toBeString()->not->toBe('');

    $this->actingAs($superAdmin)
        ->postJson('/api/v1/admin/development-reset/execute', [
            'mode' => 'TEST_ONLY',
            'arm_token' => $token,
            'access_code' => '123456',
            'confirmation_phrase' => 'RESET TEST CUSTOMERS',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'TEST_ONLY')
        ->assertJsonPath('total_deleted', 0);
});
