<?php

use App\Models\AccountingJournalEntry;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryReceipt;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Foundation\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->today = app(BusinessClock::class)->today();

    $group = ReferenceGroup::query()
        ->where('business_id', $this->user->business_id)
        ->where('code', 'PAYMENT_METHOD')
        ->firstOrFail();
    $this->method = ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'BDO-AP-TEST',
        'display_value' => 'BDO Bank Transfer',
        'sort_order' => 10,
        'is_system' => false,
        'active' => true,
    ]);
    $this->bank = ChartOfAccount::query()->create([
        'business_id' => $this->user->business_id,
        'code' => '1002-AP',
        'name' => 'Cash in Bank - BDO AP Test',
        'account_type' => 'ASSET',
        'account_group' => 'Cash and Cash Equivalents',
        'normal_balance' => 'DEBIT',
        'posting_type' => 'POSTING',
        'cash_flow_classification' => 'NON_CASH',
        'is_opex' => false,
        'is_cash_account' => true,
        'is_funding_source' => true,
        'active' => true,
    ]);
    AccountingPaymentMethodMapping::query()->create([
        'business_id' => $this->user->business_id,
        'payment_method_reference_id' => $this->method->id,
        'cash_account_id' => $this->bank->id,
    ]);
});

test('accounts payable lists unpaid expenses and settles them through mapped payment methods', function () {
    $utilities = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6102')->firstOrFail();
    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'expenses')],
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => $this->today,
        'vendor_payee' => 'Utility Vendor',
        'payment_status' => 'UNPAID',
        'lines' => [['account_id' => $utilities->id, 'description' => 'Electricity', 'amount' => 3500]],
    ])->assertCreated()->json('expense.id');
    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk();

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today)
        ->assertOk()
        ->assertJsonPath('rows.0.ap_control_no', 'AP-'.date('md', strtotime($this->today)).'-0001')
        ->assertJsonPath('rows.0.source_type', 'EXPENSE')
        ->assertJsonPath('rows.0.outstanding_amount', 3500)
        ->assertJsonPath('summary.total', 3500)
        ->assertJsonPath('summary.reconciliation_status', 'BALANCED');

    $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/expense/'.$expenseId.'/pay', [
        'payment_splits' => [[
            'payment_method_reference_id' => $this->method->id,
            'amount' => 3500,
            'reference' => 'BDO-EXP-001',
        ]],
    ])->assertOk()->assertJsonPath('source.payment_status', 'PAID');

    $payment = AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'PAYMENT')->with('lines')->firstOrFail();
    expect((float) $payment->lines->firstWhere('account_key', 'ACCOUNTS_PAYABLE')->debit)->toBe(3500.0)
        ->and((float) $payment->lines->where('account_id', $this->bank->id)->sum('credit'))->toBe(3500.0);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?state=PAID')
        ->assertOk()->assertJsonPath('rows.0.payment_status', 'PAID')
        ->assertJsonPath('rows.0.outstanding_amount', 0);
});

test('accounts payable settles an unpaid stock receipt and clears supplier payable', function () {
    $receipt = InventoryReceipt::withoutEvents(fn () => InventoryReceipt::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'IR-AP-TEST-001',
        'branch_id' => $this->user->default_branch_id,
        'received_date' => $this->today,
        'supplier_name' => 'Inventory Supplier',
        'reference_no' => 'INV-AP-001',
        'status' => 'POSTED',
        'total_cost' => 600,
        'delivery_fee' => 50,
        'payment_status' => 'UNPAID',
        'created_by' => $this->user->id,
        'posted_at' => now(),
    ]));

    app(AccountingJournalService::class)->postEntry(
        $this->user->business_id,
        $this->user->default_branch_id,
        $this->today,
        'INVENTORY_RECEIPT',
        $receipt->id,
        'POST',
        $receipt->code,
        'Test stock receipt payable',
        [
            ['account_key' => 'INVENTORY_RETAIL_PRODUCTS', 'debit' => 650, 'credit' => 0],
            ['account_key' => 'ACCOUNTS_PAYABLE', 'debit' => 0, 'credit' => 650],
        ],
        $this->user->id,
    );

    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&source=INVENTORY_RECEIPT')
        ->assertOk()
        ->assertJsonPath('rows.0.code', 'IR-AP-TEST-001')
        ->assertJsonPath('rows.0.outstanding_amount', 650);

    $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/inventory-receipt/'.$receipt->id.'/pay', [
        'payment_splits' => [[
            'payment_method_reference_id' => $this->method->id,
            'amount' => 650,
            'reference' => 'BDO-STOCK-001',
        ]],
    ])->assertOk()->assertJsonPath('source.payment_status', 'PAID');

    $payment = AccountingJournalEntry::query()->where('source_type', 'INVENTORY_RECEIPT')->where('source_id', $receipt->id)->where('source_event', 'PAYMENT')->with('lines')->firstOrFail();
    expect((float) $payment->lines->firstWhere('account_key', 'ACCOUNTS_PAYABLE')->debit)->toBe(650.0)
        ->and((float) $payment->lines->where('account_id', $this->bank->id)->sum('credit'))->toBe(650.0);
});

test('petty cash module exposes posting accounts and posts an auditable entry', function () {
    $bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();

    $this->actingAs($this->user)->getJson('/api/v1/petty-cash')
        ->assertOk()
        ->assertJsonFragment(['code' => $bank->code, 'name' => $bank->name]);

    $response = $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => $this->today,
        'transaction_type' => 'REPLENISHMENT',
        'direction' => 'IN',
        'counterpart_account_id' => $bank->id,
        'amount' => 1000,
        'reference' => 'PC-TEST-001',
        'description' => 'Petty cash replenishment test',
    ])->assertCreated()->assertJsonPath('transaction.amount', 1000);

    $id = (int) $response->json('transaction.id');
    $journal = AccountingJournalEntry::query()->where('source_type', 'PETTY_CASH')->where('source_id', $id)->with('lines')->firstOrFail();
    expect((float) $journal->lines->firstWhere('account_key', 'PETTY_CASH')->debit)->toBe(1000.0)
        ->and((float) $journal->lines->where('account_id', $bank->id)->sum('credit'))->toBe(1000.0);
});


test('manual AP entry creates one unpaid liability and rejects duplicate supplier bill', function () {
    $utilities = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6102')->firstOrFail();
    $payload = [
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => $this->today,
        'vendor_payee' => 'Monthly Electricity',
        'reference' => 'MERALCO-BILL-2026-11',
        'due_date' => \Carbon\CarbonImmutable::parse($this->today)->addDays(15)->toDateString(),
        'description' => 'November electricity bill',
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'expenses')],
        'lines' => [['account_id' => $utilities->id, 'description' => 'Electricity', 'amount' => 1500]],
    ];
    $created = $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/manual', $payload)
        ->assertCreated()->assertJsonPath('source_type', 'EXPENSE');
    $id = $created->json('source_id');
    expect(\App\Models\Expense::findOrFail($id)->payment_status)->toBe('UNPAID')
        ->and(\App\Models\Expense::findOrFail($id)->due_date->toDateString())->toBe($payload['due_date']);
    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&due_from='.$payload['due_date'].'&due_to='.$payload['due_date'])
        ->assertOk()->assertJsonPath('rows.0.due_date', $payload['due_date']);
    $this->actingAs($this->user)->getJson('/api/v1/accounting/accounts-payable?as_of='.$this->today.'&due_to='.$this->today)
        ->assertOk()->assertJsonCount(0, 'rows');
    expect(AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $id)->where('source_event', 'POST')->count())->toBe(1);
    $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/manual', $payload)
        ->assertStatus(422);
    expect(\App\Models\Expense::query()->where('vendor_payee', 'Monthly Electricity')->count())->toBe(1);
});

test('disbursements shows payment once and excludes the unpaid accrual', function () {
    $utilities = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6102')->firstOrFail();
    $id = $this->actingAs($this->user)->postJson('/api/v1/expenses', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'expenses')],
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => $this->today,
        'vendor_payee' => 'Utility Vendor', 'reference' => 'UTL-DISB-001',
        'payment_status' => 'UNPAID',
        'lines' => [['account_id' => $utilities->id, 'amount' => 2750]],
    ])->assertCreated()->json('expense.id');
    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$id.'/post')->assertOk();
    $this->actingAs($this->user)->getJson('/api/v1/accounting/disbursements?source=EXPENSE')
        ->assertOk()->assertJsonPath('summary.count', 0);
    $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/expense/'.$id.'/pay', [
        'payment_splits' => [['funding_account_id' => $this->bank->id, 'amount' => 2750, 'reference' => 'BDO-UTL-001']],
    ])->assertOk();
    $this->actingAs($this->user)->getJson('/api/v1/accounting/disbursements?source=EXPENSE')
        ->assertOk()->assertJsonPath('summary.count', 1)->assertJsonPath('rows.0.amount', 2750)
        ->assertJsonPath('rows.0.source_label', 'Manual Entry (AP Expense)');
    $this->actingAs($this->user)->getJson('/api/v1/accounting/disbursements?source=MANUAL_AP')
        ->assertOk()->assertJsonPath('summary.count', 1);
    $this->actingAs($this->user)->getJson('/api/v1/accounting/disbursements?source=MANUAL_ENTRY')
        ->assertOk()->assertJsonPath('summary.count', 0);
    $this->actingAs($this->user)->postJson('/api/v1/accounting/accounts-payable/expense/'.$id.'/pay', [
        'payment_splits' => [['funding_account_id' => $this->bank->id, 'amount' => 2750]],
    ])->assertStatus(422);
    $this->actingAs($this->user)->getJson('/api/v1/accounting/disbursements?source=EXPENSE')
        ->assertOk()->assertJsonPath('summary.count', 1);
});
