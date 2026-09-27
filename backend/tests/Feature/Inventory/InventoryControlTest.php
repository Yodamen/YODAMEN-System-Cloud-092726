<?php

use App\Models\AccountingJournalEntry;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Services\Foundation\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->seed(\Database\Seeders\RetailPosPermissionSeeder::class);
    $this->seed(\Database\Seeders\ProductMasterReferenceSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->today = app(BusinessClock::class)->today();
});

function inventoryControlProduct($test, string $name, string $sku, float $cost = 100): RetailProduct
{
    $id = $test->actingAs($test->user)->postJson('/api/v1/inventory/products', [
        'sku' => $sku,
        'name' => $name,
        'category' => 'Treatment',
        'brand' => 'YODAMEN',
        'unit' => 'pc',
        'stock_type' => 'INVENTORY',
        'unit_cost' => $cost,
        'selling_price' => 250,
        'reorder_point' => 2,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    return RetailProduct::query()->findOrFail($id);
}

function inventoryControlSupplier($test, RetailProduct $product, string $companyName = 'Test Supplier'): int
{
    return (int) $test->actingAs($test->user)->postJson('/api/v1/inventory/suppliers', [
        'company_name' => $companyName,
        'code_name' => substr(preg_replace('/[^A-Za-z0-9]+/', '', $companyName), 0, 20),
        'channel' => 'Phone',
        'status' => 'ACTIVE',
        'item_ids' => [$product->id],
    ])->assertCreated()->json('supplier.id');
}

function inventoryControlReceiptLine(RetailProduct $product, int $purchaseQuantity, float $purchaseUnitCost): array
{
    return [
        'retail_product_id' => $product->id,
        'purchase_quantity' => $purchaseQuantity,
        'purchase_uom' => $product->purchase_uom ?: $product->unit,
        'conversion_factor' => (float) ($product->purchase_to_basic_factor ?: 1),
        'purchase_unit_cost' => $purchaseUnitCost,
    ];
}

test('stock received requires supporting proof', function () {
    $product = inventoryControlProduct($this, 'Required Receipt Proof Item', 'SKU-RECEIPT-PROOF-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Required Receipt Proof Supplier');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 1, 100)],
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['attachment_tokens']);
});

test('stock received posts receipt lines stock balance and movement ledger', function () {
    $product = inventoryControlProduct($this, 'Receipt Test Item', 'SKU-RECEIPT-001', 100);

    $supplierId = inventoryControlSupplier($this, $product, 'Test Supplier');

    $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'reference_no' => 'DR-1001',
        'lines' => [inventoryControlReceiptLine($product, 5, 120)],
    ])->assertCreated()
      ->assertJsonPath('receipt.lines.0.quantity_received', 5)
      ->assertJsonPath('receipt.lines.0.unit_cost', 120);

    expect((int) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(5);
    expect(InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'STOCK_RECEIVED')->count())->toBe(1);
    expect((float) RetailProduct::query()->findOrFail($product->id)->unit_cost)->toBe(120.0);
    expect($response->json('receipt.code'))->toStartWith('IR-');

    $this->actingAs($this->user)
        ->getJson('/api/v1/inventory/receipts?branch_id='.$this->user->default_branch_id.'&supplier_id='.$supplierId.'&product_id='.$product->id)
        ->assertOk()
        ->assertJsonCount(1, 'receipts')
        ->assertJsonPath('receipts.0.code', $response->json('receipt.code'));
});

test('paid stock receipt uses mapped payment account and posts freight-in separately', function () {
    $product = inventoryControlProduct($this, 'Paid Receipt Item', 'SKU-RECEIPT-PAID', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Paid Receipt Supplier');

    $paymentGroup = ReferenceGroup::query()
        ->where('business_id', $this->user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();
    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'BDO-STOCK',
        'display_value' => 'BDO Bank Transfer',
        'sort_order' => 10,
        'is_system' => false,
        'active' => true,
    ]);
    $bdo = ChartOfAccount::query()->create([
        'business_id' => $this->user->business_id,
        'code' => '1002-98',
        'name' => 'Cash in Bank - BDO Stock Test',
        'account_type' => 'ASSET',
        'account_group' => 'Cash and Cash Equivalents',
        'normal_balance' => 'DEBIT',
        'posting_type' => 'POSTING',
        'cash_flow_classification' => 'OPERATING',
        'is_opex' => false,
        'is_cash_account' => true,
        'is_funding_source' => true,
        'active' => true,
    ]);
    AccountingPaymentMethodMapping::query()->create([
        'business_id' => $this->user->business_id,
        'payment_method_reference_id' => $method->id,
        'cash_account_id' => $bdo->id,
    ]);

    $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'reference_no' => 'DR-PAID-001',
        'delivery_fee' => 50,
        'payment_status' => 'PAID',
        'payment_method_reference_id' => $method->id,
        'payment_reference' => 'BDO-REF-001',
        'lines' => [inventoryControlReceiptLine($product, 5, 120)],
    ])->assertCreated()
      ->assertJsonPath('receipt.payment_status', 'PAID')
      ->assertJsonPath('receipt.payment_method', 'BDO Bank Transfer')
      ->assertJsonPath('receipt.payment_reference', 'BDO-REF-001')
      ->assertJsonPath('receipt.grand_total', 650);

    $receiptId = (int) $response->json('receipt.id');
    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'INVENTORY_RECEIPT')
        ->where('source_id', $receiptId)
        ->where('source_event', 'POST')
        ->with('lines')
        ->firstOrFail();

    expect((float) $journal->lines->sum('debit'))->toBe(650.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(650.0)
        ->and((float) $journal->lines->where('account_id', $bdo->id)->sum('credit'))->toBe(650.0)
        ->and((float) $journal->lines->where('account_key', 'INVENTORY_FREIGHT_IN')->sum('debit'))->toBe(50.0)
        ->and(AccountingJournalEntry::query()->where('source_type', 'INVENTORY_MOVEMENT')->whereIn('source_id', InventoryMovement::query()->where('reference_code', $response->json('receipt.code'))->pluck('id'))->count())->toBe(0);
});

test('unpaid stock receipt posts the full grand total to accounts payable without payment lines', function () {
    $product = inventoryControlProduct($this, 'Unpaid Receipt Item', 'SKU-RECEIPT-UNPAID', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Unpaid Receipt Supplier');

    $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'reference_no' => 'DR-UNPAID-001',
        'delivery_fee' => 50,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 5, 120)],
    ])->assertCreated()
      ->assertJsonPath('receipt.payment_status', 'UNPAID')
      ->assertJsonPath('receipt.grand_total', 650)
      ->assertJsonCount(0, 'receipt.payment_splits');

    $receiptId = (int) $response->json('receipt.id');
    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'INVENTORY_RECEIPT')
        ->where('source_id', $receiptId)
        ->where('source_event', 'POST')
        ->with('lines')
        ->firstOrFail();

    expect((float) $journal->lines->where('account_key', 'ACCOUNTS_PAYABLE')->sum('credit'))->toBe(650.0);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&source=INVENTORY_RECEIPT')
        ->assertOk()
        ->assertJsonPath('rows.0.code', $response->json('receipt.code'))
        ->assertJsonPath('rows.0.outstanding_amount', 650);
});

test('unpaid stock receipt rejects payment lines so settlement is handled through accounts payable', function () {
    $product = inventoryControlProduct($this, 'Unpaid With Split Item', 'SKU-RECEIPT-UNPAID-SPLIT', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Unpaid With Split Supplier');
    $bank = ChartOfAccount::query()
        ->where('business_id', $this->user->business_id)
        ->where('system_key', 'CASH_IN_BANK')
        ->firstOrFail();

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'payment_splits' => [[
            'funding_account_id' => $bank->id,
            'amount' => 100,
            'reference' => 'SHOULD-NOT-POST',
        ]],
        'lines' => [inventoryControlReceiptLine($product, 1, 100)],
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Unpaid stock receipts cannot include payment lines. Post the full balance to Accounts Payable, then settle it from Accounts Payable when payment is made.');
});

test('paid stock receipt is blocked when payment method has no accounting mapping', function () {
    $product = inventoryControlProduct($this, 'Unmapped Receipt Item', 'SKU-RECEIPT-UNMAPPED', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Unmapped Receipt Supplier');
    $paymentGroup = ReferenceGroup::query()->where('business_id', $this->user->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $method = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'UNMAPPED-STOCK',
        'display_value' => 'Unmapped Bank',
        'sort_order' => 20,
        'is_system' => false,
        'active' => true,
    ]);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'PAID',
        'payment_method_reference_id' => $method->id,
        'lines' => [inventoryControlReceiptLine($product, 1, 100)],
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Map Unmapped Bank to an active Expense / Supplier Funding Source under Admin → Accounting Mapping before posting this paid stock receipt.');
});

test('audit count is blind in draft and approval posts variance to stock', function () {
    $product = inventoryControlProduct($this, 'Count Test Item', 'SKU-COUNT-001');
    $supplierId = inventoryControlSupplier($this, $product, 'Count Test Supplier');
    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();

    $start = $this->actingAs($this->user)->postJson('/api/v1/inventory/counts', [
        'branch_id' => $this->user->default_branch_id,
        'count_date' => $this->today,
        'scope_type' => 'SELECTED',
        'product_ids' => [$product->id],
    ])->assertCreated()
      ->assertJsonPath('count.status', 'DRAFT')
      ->assertJsonPath('count.blind_count', true)
      ->assertJsonPath('count.lines.0.system_quantity', null);

    $countId = (int) $start->json('count.id');
    $lineId = (int) $start->json('count.lines.0.id');

    $this->actingAs($this->user)->putJson('/api/v1/inventory/counts/'.$countId, [
        'lines' => [[
            'id' => $lineId,
            'actual_quantity' => 8,
            'line_notes' => 'Two pieces short during physical count.',
        ]],
    ])->assertOk();

    $this->actingAs($this->user)->postJson('/api/v1/inventory/counts/'.$countId.'/submit')
        ->assertOk()
        ->assertJsonPath('count.status', 'SUBMITTED')
        ->assertJsonPath('count.lines.0.system_quantity', 10)
        ->assertJsonPath('count.lines.0.actual_quantity', 8)
        ->assertJsonPath('count.lines.0.variance_quantity', -2);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/counts/'.$countId.'/approve')
        ->assertOk()
        ->assertJsonPath('count.status', 'APPROVED');

    expect((int) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(8);
    expect((float) InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'INVENTORY_COUNT_ADJUSTMENT')->value('quantity_change'))->toBe(-2.0);
});

test('audit count approval is blocked when stock changed after count started', function () {
    $product = inventoryControlProduct($this, 'Count Conflict Item', 'SKU-COUNT-002');
    $supplierId = inventoryControlSupplier($this, $product, 'Count Conflict Supplier');
    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'lines' => [inventoryControlReceiptLine($product, 5, 100)],
    ])->assertCreated();

    $start = $this->actingAs($this->user)->postJson('/api/v1/inventory/counts', [
        'branch_id' => $this->user->default_branch_id,
        'count_date' => $this->today,
        'scope_type' => 'SELECTED',
        'product_ids' => [$product->id],
    ])->assertCreated();

    $countId = (int) $start->json('count.id');
    $lineId = (int) $start->json('count.lines.0.id');
    $this->actingAs($this->user)->putJson('/api/v1/inventory/counts/'.$countId, [
        'lines' => [['id' => $lineId, 'actual_quantity' => 5]],
    ])->assertOk();
    $this->actingAs($this->user)->postJson('/api/v1/inventory/counts/'.$countId.'/submit')->assertOk();

    $adjustmentId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/products/'.$product->id.'/adjust-stock', [
        'branch_id' => $this->user->default_branch_id,
        'quantity_change' => 1,
        'reason_reference_id' => $this->workflowReasonId('FOUND_STOCK'),
        'reason' => 'Movement after physical count started',
    ])->assertCreated()
      ->assertJsonPath('request.status', 'PENDING')
      ->json('request.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustment-requests/'.$adjustmentId.'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/counts/'.$countId.'/approve')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Stock changed after this audit count started. Cancel this count and start a new one before approval.');
});

test('audit count options group categories by stock type and allow scoped counts', function () {
    $inventory = inventoryControlProduct($this, 'Inventory Audit Item', 'SKU-AUD-INV');

    $nonInventoryId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'name' => 'Office Bond Paper',
        'category' => 'Office Supplies',
        'unit' => 'ream',
        'stock_type' => 'NON_INVENTORY',
        'unit_cost' => 220,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    $assetId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'name' => 'Cordless Clipper',
        'category' => 'Barber Equipment',
        'unit' => 'pc',
        'stock_type' => 'ASSET',
        'unit_cost' => 3500,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    $this->actingAs($this->user)->getJson('/api/v1/inventory/counts')
        ->assertOk()
        ->assertJsonFragment(['Office Supplies'])
        ->assertJsonFragment(['Barber Equipment']);

    $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/counts', [
        'branch_id' => $this->user->default_branch_id,
        'count_date' => $this->today,
        'scope_type' => 'STOCK_TYPE',
        'stock_type' => 'NON_INVENTORY',
        'categories' => [],
        'product_ids' => [$nonInventoryId],
    ])->assertCreated()
      ->assertJsonPath('count.scope_type', 'STOCK_TYPE')
      ->assertJsonPath('count.stock_type', 'NON_INVENTORY')
      ->assertJsonPath('count.scope_label', 'Full Count - Non-inventory')
      ->assertJsonCount(1, 'count.lines')
      ->assertJsonPath('count.lines.0.retail_product_id', $nonInventoryId);

    expect($response->json('count.lines.0.retail_product_id'))->not->toBe($inventory->id)
        ->and($response->json('count.lines.0.retail_product_id'))->not->toBe($assetId);
});

test('audit count can target selected categories under one stock type', function () {
    $firstId = $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'name' => 'Cleaning Cloth',
        'category' => 'Cleaning Supplies',
        'unit' => 'pc',
        'stock_type' => 'NON_INVENTORY',
        'unit_cost' => 20,
        'status' => 'ACTIVE',
    ])->assertCreated()->json('product.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/products', [
        'name' => 'Printer Paper',
        'category' => 'Office Supplies',
        'unit' => 'ream',
        'stock_type' => 'NON_INVENTORY',
        'unit_cost' => 200,
        'status' => 'ACTIVE',
    ])->assertCreated();

    $this->actingAs($this->user)->postJson('/api/v1/inventory/counts', [
        'branch_id' => $this->user->default_branch_id,
        'count_date' => $this->today,
        'scope_type' => 'CATEGORIES',
        'stock_type' => 'NON_INVENTORY',
        'categories' => ['Cleaning Supplies'],
        'product_ids' => [$firstId],
    ])->assertCreated()
      ->assertJsonPath('count.scope_type', 'CATEGORIES')
      ->assertJsonPath('count.categories.0', 'Cleaning Supplies')
      ->assertJsonPath('count.lines.0.name', 'Cleaning Cloth')
      ->assertJsonCount(1, 'count.lines');
});

test('approved manual inventory adjustment can be reversed only after a reversal approval', function () {
    $product = inventoryControlProduct($this, 'Adjustment Reversal Item', 'SKU-ADJ-REV-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Adjustment Reversal Supplier');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'lines' => [inventoryControlReceiptLine($product, 5, 100)],
    ])->assertCreated();

    $adjustmentId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/products/'.$product->id.'/adjust-stock', [
        'branch_id' => $this->user->default_branch_id,
        'quantity_change' => 2,
        'reason_reference_id' => $this->workflowReasonId('FOUND_STOCK'),
        'reason' => 'Temporary manual surplus for reversal test',
    ])->assertCreated()->json('request.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustment-requests/'.$adjustmentId.'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');
    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(7.0);

    $reversalId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustment-requests/'.$adjustmentId.'/reversal-requests', [
        'reason_reference_id' => $this->workflowReasonId('ACCIDENTAL_ENTRY'),
        'reason' => 'Original manual adjustment was entered in error.',
    ])->assertCreated()
      ->assertJsonPath('request.request_type', 'REVERSAL')
      ->assertJsonPath('request.status', 'PENDING')
      ->json('request.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustment-requests/'.$adjustmentId.'/reversal-requests', [
        'reason_reference_id' => $this->workflowReasonId('ACCIDENTAL_ENTRY'),
        'reason' => 'A second reversal must wait for the existing approval.',
    ])->assertStatus(422);

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(7.0);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustment-requests/'.$reversalId.'/approve')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(5.0)
        ->and(InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'ADJUSTMENT_REVERSAL')->count())->toBe(1);
});

test('stock receipt item correction remains pending until approval then adjusts stock and accounts payable', function () {
    $product = inventoryControlProduct($this, 'Receipt Correction Item', 'SKU-RCPT-CORR-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Receipt Correction Supplier');

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();

    $receiptId = (int) $receiptResponse->json('receipt.id');
    $lineId = (int) $receiptResponse->json('receipt.lines.0.id');
    $requestId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts/'.$receiptId.'/lines/'.$lineId.'/correction-requests', [
        'corrected_purchase_quantity' => 8,
        'corrected_purchase_unit_cost' => 90,
        'reason_reference_id' => $this->workflowReasonId('WRONG_QUANTITY'),
        'reason' => 'Supplier invoice quantity and unit cost were encoded incorrectly.',
    ])->assertCreated()
      ->assertJsonPath('request.status', 'PENDING')
      ->json('request.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts/'.$receiptId.'/reversal-requests', [
        'reason_reference_id' => $this->workflowReasonId('DUPLICATE_RECEIPT'),
        'reason' => 'A reversal must not be accepted while the correction is pending.',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'This stock receipt already has a pending correction or reversal request. Approve or reject it before creating another request.');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(10.0);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipt-action-requests/'.$requestId.'/approve-correction')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(8.0);

    $this->actingAs($this->user)->getJson('/api/v1/inventory/receipts?branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('receipts.0.total_cost', 720);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&source=INVENTORY_RECEIPT')
        ->assertOk()
        ->assertJsonPath('summary.total', 720);

    $journal = AccountingJournalEntry::query()->where('source_type', 'INVENTORY_RECEIPT_ACTION')->where('source_id', $requestId)->where('source_event', 'CORRECTION')->with('lines')->firstOrFail();
    expect((float) $journal->lines->where('account_key', 'ACCOUNTS_PAYABLE')->sum('debit'))->toBe(280.0)
        ->and((float) $journal->lines->sum('debit'))->toBe(280.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(280.0);
});

test('full stock receipt reversal requires approval and reverses inventory and the receipt accounting', function () {
    $product = inventoryControlProduct($this, 'Receipt Reversal Item', 'SKU-RCPT-REV-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Receipt Reversal Supplier');

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 5, 100)],
    ])->assertCreated();
    $receiptId = (int) $receiptResponse->json('receipt.id');

    $requestId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts/'.$receiptId.'/reversal-requests', [
        'reason_reference_id' => $this->workflowReasonId('DUPLICATE_RECEIPT'),
        'reason' => 'Entire stock receipt was duplicated and must be voided.',
    ])->assertCreated()
      ->assertJsonPath('request.action_type', 'REVERSAL')
      ->assertJsonPath('request.status', 'PENDING')
      ->json('request.id');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(5.0);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipt-action-requests/'.$requestId.'/approve-reversal')
        ->assertOk()
        ->assertJsonPath('request.status', 'APPROVED');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(0.0)
        ->and(InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'STOCK_RECEIPT_REVERSAL')->count())->toBe(1);

    $reversal = AccountingJournalEntry::query()->where('source_type', 'INVENTORY_RECEIPT_ACTION')->where('source_id', $requestId)->where('source_event', 'REVERSAL')->with('lines')->firstOrFail();
    expect((float) $reversal->lines->where('account_key', 'ACCOUNTS_PAYABLE')->sum('debit'))->toBe(500.0)
        ->and((float) $reversal->lines->sum('debit'))->toBe(500.0)
        ->and((float) $reversal->lines->sum('credit'))->toBe(500.0);
});

test('approved stock return reduces on hand and supplier payable without rewriting the original receipt', function () {
    $product = inventoryControlProduct($this, 'Stock Return AP Item', 'SKU-RETURN-AP-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Stock Return AP Supplier');

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();
    $receiptId = (int) $receiptResponse->json('receipt.id');
    $lineId = (int) $receiptResponse->json('receipt.lines.0.id');

    $returnId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns', [
        'inventory_receipt_id' => $receiptId,
        'branch_id' => $this->user->default_branch_id,
        'return_date' => $this->today,
        'reason_reference_id' => $this->workflowReasonId('QUALITY_ISSUE'),
        'reason' => 'Supplier accepted three defective units for return.',
        'lines' => [['inventory_receipt_line_id' => $lineId, 'quantity_returned' => 3]],
    ])->assertCreated()
      ->assertJsonPath('return.status', 'PENDING')
      ->json('return.id');

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(10.0);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns/'.$returnId.'/approve')
        ->assertOk()
        ->assertJsonPath('return.status', 'APPROVED')
        ->assertJsonPath('return.ap_credit_amount', 300)
        ->assertJsonPath('return.supplier_refund_receivable', 0);

    expect((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(7.0);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&source=INVENTORY_RECEIPT')
        ->assertOk()
        ->assertJsonPath('summary.total', 700);
});

test('stock return blocks another pending request for the same receipt until the first request is resolved', function () {
    $product = inventoryControlProduct($this, 'Pending Stock Return Item', 'SKU-RETURN-PENDING-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Pending Stock Return Supplier');

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'UNPAID',
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();
    $receiptId = (int) $receiptResponse->json('receipt.id');
    $lineId = (int) $receiptResponse->json('receipt.lines.0.id');

    $payload = [
        'inventory_receipt_id' => $receiptId,
        'branch_id' => $this->user->default_branch_id,
        'return_date' => $this->today,
        'reason_reference_id' => $this->workflowReasonId('QUALITY_ISSUE'),
        'reason' => 'Supplier review required before another return can be submitted.',
        'lines' => [['inventory_receipt_line_id' => $lineId, 'quantity_returned' => 2]],
    ];

    $firstReturnId = (int) $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/stock-returns', $payload)
        ->assertCreated()
        ->assertJsonPath('return.status', 'PENDING')
        ->json('return.id');

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/stock-returns', $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This stock receipt already has a pending Stock Return request. Approve or reject it before creating another request.');

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/receipts/'.$receiptId.'/lines/'.$lineId.'/correction-requests', [
            'corrected_purchase_quantity' => 9,
            'corrected_purchase_unit_cost' => 100,
            'reason_reference_id' => $this->workflowReasonId('WRONG_QUANTITY'),
            'reason' => 'Receipt quantity correction should wait for the pending Stock Return.',
        ])
        ->assertStatus(422);

    $options = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-returns')->assertOk();
    expect(collect($options->json('options.receipts'))->pluck('id')->all())->not->toContain($receiptId);

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/stock-returns/'.$firstReturnId.'/reject', [
            'review_reason_reference_id' => $this->workflowReasonId('INVALID_REQUEST'),
            'notes' => 'Reject first request so a corrected request can be submitted.',
        ])
        ->assertOk()
        ->assertJsonPath('return.status', 'REJECTED');

    $this->actingAs($this->user)
        ->postJson('/api/v1/inventory/stock-returns', $payload)
        ->assertCreated()
        ->assertJsonPath('return.status', 'PENDING');
});

test('paid stock return creates supplier refund receivable and clears it when refund reaches a bank', function () {
    $product = inventoryControlProduct($this, 'Paid Stock Return Item', 'SKU-RETURN-PAID-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Paid Stock Return Supplier');
    $bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'PAID',
        'payment_splits' => [[
            'funding_account_id' => $bank->id,
            'amount' => 1000,
            'reference' => 'PAID-RETURN-TEST',
        ]],
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();
    $receiptId = (int) $receiptResponse->json('receipt.id');
    $lineId = (int) $receiptResponse->json('receipt.lines.0.id');

    $returnId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns', [
        'inventory_receipt_id' => $receiptId,
        'branch_id' => $this->user->default_branch_id,
        'return_date' => $this->today,
        'reason_reference_id' => $this->workflowReasonId('QUALITY_ISSUE'),
        'reason' => 'Supplier agreed to refund two paid defective units.',
        'lines' => [['inventory_receipt_line_id' => $lineId, 'quantity_returned' => 2]],
    ])->assertCreated()->json('return.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns/'.$returnId.'/approve')
        ->assertOk()
        ->assertJsonPath('return.ap_credit_amount', 0)
        ->assertJsonPath('return.supplier_refund_receivable', 200)
        ->assertJsonPath('return.refund_remaining', 200);

    // Supplier refund received must require proof even though the Stock Return request itself is optional.
    $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns/'.$returnId.'/refunds', [
        'refund_date' => $this->today,
        'receiving_account_id' => $bank->id,
        'amount' => 200,
        'reference' => 'SUPPLIER-REFUND-NO-PROOF',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['attachment_tokens']);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-returns/'.$returnId.'/refunds', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-returns')],
        'refund_date' => $this->today,
        'receiving_account_id' => $bank->id,
        'amount' => 200,
        'reference' => 'SUPPLIER-REFUND-001',
    ])->assertOk()
      ->assertJsonPath('return.refund_remaining', 0);

    $refundJournal = AccountingJournalEntry::query()->where('source_type', 'INVENTORY_STOCK_RETURN_REFUND')->where('source_event', 'RECEIVE')->with('lines')->firstOrFail();
    expect((float) $refundJournal->lines->where('account_id', $bank->id)->sum('debit'))->toBe(200.0)
        ->and((float) $refundJournal->lines->where('account_key', 'SUPPLIER_REFUNDS_RECEIVABLE')->sum('credit'))->toBe(200.0)
        ->and((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(8.0);
});

test('paid stock receipt downward correction tracks and settles supplier refund receivable', function () {
    $product = inventoryControlProduct($this, 'Paid Correction Refund Item', 'SKU-CORR-REFUND-001', 100);
    $supplierId = inventoryControlSupplier($this, $product, 'Paid Correction Refund Supplier');
    $bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();

    $receiptResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_id' => $supplierId,
        'payment_status' => 'PAID',
        'payment_splits' => [[
            'funding_account_id' => $bank->id,
            'amount' => 1000,
            'reference' => 'PAID-CORRECTION-TEST',
        ]],
        'lines' => [inventoryControlReceiptLine($product, 10, 100)],
    ])->assertCreated();

    $receiptId = (int) $receiptResponse->json('receipt.id');
    $lineId = (int) $receiptResponse->json('receipt.lines.0.id');
    $requestId = (int) $this->actingAs($this->user)->postJson('/api/v1/inventory/receipts/'.$receiptId.'/lines/'.$lineId.'/correction-requests', [
        'corrected_purchase_quantity' => 10,
        'corrected_purchase_unit_cost' => 90,
        'reason_reference_id' => $this->workflowReasonId('WRONG_UNIT_COST'),
        'reason' => 'Supplier invoice unit cost was overstated by ten pesos per unit.',
    ])->assertCreated()->json('request.id');

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipt-action-requests/'.$requestId.'/approve-correction')
        ->assertOk()
        ->assertJsonPath('request.supplier_refund_receivable', 100)
        ->assertJsonPath('request.refund_remaining', 100);

    $this->actingAs($this->user)->postJson('/api/v1/inventory/receipt-action-requests/'.$requestId.'/refunds', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'stock-received')],
        'refund_date' => $this->today,
        'receiving_account_id' => $bank->id,
        'amount' => 100,
        'reference' => 'CORR-REFUND-001',
    ])->assertOk()
      ->assertJsonPath('request.refund_remaining', 0);

    $refundJournal = AccountingJournalEntry::query()->where('source_type', 'INVENTORY_RECEIPT_ACTION_REFUND')->where('source_event', 'RECEIVE')->with('lines')->firstOrFail();
    expect((float) $refundJournal->lines->where('account_id', $bank->id)->sum('debit'))->toBe(100.0)
        ->and((float) $refundJournal->lines->where('account_key', 'SUPPLIER_REFUNDS_RECEIVABLE')->sum('credit'))->toBe(100.0);
});
