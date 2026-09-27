<?php

use App\Models\ChartOfAccount;
use App\Services\Accounting\AccountingJournalService;
use App\Http\Requests\Api\V1\Sales\StoreTransactionActionRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->businessId = (int) $this->user->business_id;
    $this->branchId = (int) $this->user->default_branch_id;
    $this->journals = app(AccountingJournalService::class);
});

function accountingAccountId($test, string $key): int
{
    return (int) ChartOfAccount::query()
        ->where('business_id', $test->businessId)
        ->where('system_key', $key)
        ->firstOrFail()
        ->id;
}

test('financial statements subtract sales returns and discounts from gross income', function () {
    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-24',
        'TEST_SALE',
        1,
        'FINALIZE',
        'SALE-TEST-001',
        'Test finalized sale',
        [
            ['account_key' => 'AR_CUSTOMERS', 'debit' => 1000, 'credit' => 0],
            ['account_key' => 'SERVICE_REVENUE', 'debit' => 0, 'credit' => 1000],
        ],
        $this->user->id,
    );

    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-24',
        'TEST_CORRECTION',
        2,
        'POST',
        'CORR-TEST-001',
        'Remove service from finalized sale',
        [
            ['account_key' => 'SALES_RETURNS', 'debit' => 300, 'credit' => 0],
            ['account_key' => 'AR_CUSTOMERS', 'debit' => 0, 'credit' => 300],
        ],
        $this->user->id,
    );

    $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/financial-statements?from=2026-09-01&to=2026-09-30&basis=ACCRUAL&cost_basis=SYSTEM')
        ->assertOk();

    expect((float) $response->json('income_statement.revenue'))->toBe(700.0)
        ->and((float) $response->json('income_statement.gross_profit'))->toBe(700.0)
        ->and((float) $response->json('income_statement.net_income'))->toBe(700.0);

    $incomeRows = collect($response->json('income_statement.income'))->keyBy('code');
    expect((float) $incomeRows['4001']['amount'])->toBe(1000.0)
        ->and((float) $incomeRows['4003']['amount'])->toBe(-300.0);
});

test('cash management and cash flow change only when an actual cash refund journal is posted', function () {
    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-24',
        'TEST_PAYMENT',
        10,
        'RECEIVE',
        'PAY-TEST-001',
        'Customer receipt',
        [
            ['account_key' => 'CASH_ON_HAND', 'debit' => 1000, 'credit' => 0],
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 0, 'credit' => 1000],
        ],
        $this->user->id,
    );

    // A correction/reversal can create Refund Due without moving cash. Once the
    // approved Refund returns money, that separate journal reduces actual cash.
    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-24',
        'TEST_REFUND',
        11,
        'CASH_REFUND',
        'REF-TEST-001',
        'Approved customer cash refund',
        [
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 300, 'credit' => 0],
            ['account_key' => 'CASH_ON_HAND', 'debit' => 0, 'credit' => 300],
        ],
        $this->user->id,
    );

    $cash = $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-management?to=2026-09-30')
        ->assertOk();
    expect((float) $cash->json('summary.total'))->toBe(700.0);

    $flow = $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-flow?from=2026-09-01&to=2026-09-30')
        ->assertOk();
    expect((float) $flow->json('summary.operating'))->toBe(700.0)
        ->and((float) $flow->json('summary.net_change'))->toBe(700.0)
        ->and((float) $flow->json('summary.ending_cash'))->toBe(700.0);

    $refundRow = collect($flow->json('rows'))->firstWhere('reference', 'REF-TEST-001');
    expect((float) $refundRow['cash_change'])->toBe(-300.0);
});


test('transaction action validation accepts refund requests and refund lines', function () {
    $formRequest = new StoreTransactionActionRequest();
    $validator = Validator::make([
        'action_type' => 'REFUND',
        'reason_code' => 'CORRECTION_REFUND_DUE',
        'remarks' => 'Return the collected amount created by the approved correction.',
        'refund_method' => 'GCash',
        'lines' => [[
            'sale_item_id' => 1,
            'line_action' => 'REFUND',
            'refund_quantity' => 0,
            'refund_amount' => 300,
        ]],
    ], $formRequest->rules());

    expect($validator->fails())->toBeFalse();
});

test('accounting transaction logs return the latest posting first', function () {
    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-20',
        'TEST_ORDERING',
        901,
        'OLDER',
        'ORDER-OLDER',
        'Older cash posting',
        [
            ['account_key' => 'CASH_ON_HAND', 'debit' => 100, 'credit' => 0],
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 0, 'credit' => 100],
        ],
        $this->user->id,
    );

    $this->journals->postEntry(
        $this->businessId,
        $this->branchId,
        '2026-09-24',
        'TEST_ORDERING',
        902,
        'NEWER',
        'ORDER-NEWER',
        'Newer cash posting',
        [
            ['account_key' => 'CASH_ON_HAND', 'debit' => 200, 'credit' => 0],
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 0, 'credit' => 200],
        ],
        $this->user->id,
    );

    $ledger = $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/general-ledger?from=2026-09-01&to=2026-09-30')
        ->assertOk();

    expect($ledger->json('rows.0.reference'))->toBe('ORDER-NEWER')
        ->and($ledger->json('rows.0.posting_date'))->toBe('2026-09-24');

    $flow = $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/cash-flow?from=2026-09-01&to=2026-09-30')
        ->assertOk();

    expect($flow->json('rows.0.reference'))->toBe('ORDER-NEWER')
        ->and($flow->json('rows.0.date'))->toBe('2026-09-24');
});
