<?php

use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
});

test('workflow reasons are filtered by applicable workflow context', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/workflow-reasons?context=INVENTORY_ADJUSTMENT')
        ->assertOk();

    $codes = collect($response->json('reasons'))->pluck('code');
    expect($codes)->toContain('DAMAGE_BREAKAGE')
        ->and($codes)->toContain('FOUND_STOCK')
        ->and($codes)->not->toContain('DUPLICATE_FUNDING');
});

test('custom workflow reason is available only in its configured contexts', function () {
    $group = ReferenceGroup::query()
        ->where('business_id', $this->user->business_id)
        ->where('code', 'WORKFLOW_REASON')
        ->firstOrFail();

    ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'CUSTOM_BRANCH_EXCEPTION',
        'display_value' => 'Custom Branch Exception',
        'sort_order' => 9990,
        'is_system' => false,
        'active' => true,
        'metadata' => ['contexts' => ['CASH_REMITTANCE_REVERSAL']],
    ]);

    $cash = $this->actingAs($this->user)
        ->getJson('/api/v1/workflow-reasons?context=CASH_REMITTANCE_REVERSAL')
        ->assertOk();
    expect(collect($cash->json('reasons'))->pluck('code'))->toContain('CUSTOM_BRANCH_EXCEPTION');

    $inventory = $this->actingAs($this->user)
        ->getJson('/api/v1/workflow-reasons?context=INVENTORY_ADJUSTMENT')
        ->assertOk();
    expect(collect($inventory->json('reasons'))->pluck('code'))->not->toContain('CUSTOM_BRANCH_EXCEPTION');
});

test('invalid workflow context is rejected', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/workflow-reasons?context=NOT_A_REAL_WORKFLOW')
        ->assertStatus(422);
});
