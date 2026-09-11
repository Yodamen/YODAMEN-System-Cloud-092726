<?php

use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

test('super admin can create and toggle a reference option', function () {
    $user = foundationUser('ROLE-SA');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/admin/references', [
            'group_code' => 'PAYMENT_METHOD',
            'code' => 'GCASH',
            'display_value' => 'GCash',
            'sort_order' => 10,
        ])
        ->assertCreated();

    $id = $response->json('reference.id');

    $this->actingAs($user)
        ->patchJson('/api/v1/admin/references/'.$id.'/toggle')
        ->assertOk()
        ->assertJsonPath('reference.active', false);
});

test('system reference code cannot be changed', function () {
    $user = foundationUser('ROLE-SA');
    $group = ReferenceGroup::query()->where('code', 'SERVICE_COUNT')->firstOrFail();
    $value = ReferenceValue::query()->where('reference_group_id', $group->id)->where('code', 'SINGLE_SESSION')->firstOrFail();

    $this->actingAs($user)
        ->putJson('/api/v1/admin/references/'.$value->id, [
            'group_code' => 'SERVICE_COUNT',
            'code' => 'ONE_SESSION',
            'display_value' => 'Single Session',
            'sort_order' => 10,
        ])
        ->assertUnprocessable();
});
