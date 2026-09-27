<?php

use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);
    $this->seed(\Database\Seeders\ProductMasterReferenceSeeder::class);
    $this->user = foundationUser('ROLE-SA');
});

test('opening balance cutover establishes cash and inventory without current period cash flow activity', function () {
    $productId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'sku' => 'SKU-OPEN-001',
        'name' => 'Opening Stock Item',
        'category' => 'Treatment',
        'brand' => 'YODAMEN',
        'unit' => 'pc',
        'stock_type' => 'INVENTORY',
        'unit_cost' => 100,
        'selling_price' => 250,
        'reorder_point' => 2,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    $cash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();

    $response = $this->actingAs($this->user)->postJson('/api/v1/admin/opening-balances', [
        'branch_id' => $this->user->default_branch_id,
        'cutover_date' => '2026-09-25',
        'cash_balances' => [['account_id' => $cash->id, 'amount' => 50000]],
        'inventory_balances' => [['retail_product_id' => $productId, 'quantity' => 10, 'unit_cost' => 125]],
    ])->assertCreated();

    expect((float) $response->json('opening_balance.cash_total'))->toBe(50000.0)
        ->and((float) $response->json('opening_balance.inventory_total'))->toBe(1250.0);

    $stock = InventoryStock::query()->where('branch_id', $this->user->default_branch_id)->where('retail_product_id', $productId)->firstOrFail();
    expect((float) $stock->on_hand)->toBe(10.0);

    expect(InventoryMovement::query()->where('retail_product_id', $productId)->where('movement_type', 'OPENING_BALANCE')->count())->toBe(1);

    $journal = AccountingJournalEntry::query()->where('source_type', 'OPENING_BALANCE')->with('lines')->firstOrFail();
    expect((float) $journal->lines->sum('debit'))->toBe(51250.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(51250.0);

    $cashReport = $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-management?to=2026-09-25&branch_id='.$this->user->default_branch_id)->assertOk();
    expect((float) $cashReport->json('summary.total'))->toBe(50000.0);

    $flow = $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-flow?from=2026-09-25&to=2026-09-25&branch_id='.$this->user->default_branch_id)->assertOk();
    expect((float) $flow->json('summary.opening_cash'))->toBe(50000.0)
        ->and((float) $flow->json('summary.net_change'))->toBe(0.0)
        ->and((float) $flow->json('summary.ending_cash'))->toBe(50000.0)
        ->and($flow->json('rows'))->toBe([]);
});

test('opening balance cutover is one time per branch', function () {
    $cash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_ON_HAND')->firstOrFail();
    $payload = [
        'branch_id' => $this->user->default_branch_id,
        'cutover_date' => '2026-09-25',
        'cash_balances' => [['account_id' => $cash->id, 'amount' => 1000]],
        'inventory_balances' => [],
    ];

    $this->actingAs($this->user)->postJson('/api/v1/admin/opening-balances', $payload)->assertCreated();
    $this->actingAs($this->user)->postJson('/api/v1/admin/opening-balances', $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Opening balances have already been posted for this branch. Use normal accounting and inventory adjustment workflows for later changes.');
});
