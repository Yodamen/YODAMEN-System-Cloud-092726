<?php

use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->rent = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6101')->firstOrFail();
    $this->utilities = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6102')->firstOrFail();
    $this->cash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1001')->firstOrFail();
    $this->bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1002')->firstOrFail();
});

function expensePayload($test, array $overrides = []): array
{
    return array_replace([
        'attachment_tokens' => [$test->supportingDocumentToken($test->user, 'expenses')],
        'branch_id' => $test->user->default_branch_id,
        'expense_date' => '2026-09-24',
        'vendor_payee' => 'Sample Landlord',
        'reference' => 'OR-EXP-001',
        'description' => 'September operating expenses',
        'payment_status' => 'PAID',
        'payment_account_id' => $test->bank->id,
        'payment_method' => 'Bank Transfer',
        'payment_reference' => 'BANK-EXP-001',
        'notes' => null,
        'lines' => [
            ['account_id' => $test->rent->id, 'description' => 'Rent', 'amount' => 10000],
            ['account_id' => $test->utilities->id, 'description' => 'Utilities', 'amount' => 2000],
        ],
    ], $overrides);
}

test('default chart of accounts cleans duplicate legacy codes and adds required operating accounts', function () {
    expect(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1102')->count())->toBe(1)
        ->and(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1102')->value('name'))->toBe('Staff Cash Advances')
        ->and(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '2006')->count())->toBe(1)
        ->and(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '2006')->value('name'))->toBe('Staff Tips Payable')
        ->and(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1004')->value('name'))->toBe('Petty Cash')
        ->and(ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '5201')->value('name'))->toBe('Retail Product Cost of Sales');
});

test('draft expense cannot be posted without a supporting document', function () {
    $payload = expensePayload($this);
    unset($payload['attachment_tokens']);

    $expenseId = $this->actingAs($this->user)
        ->postJson('/api/v1/expenses', $payload)
        ->assertCreated()
        ->assertJsonPath('expense.status', 'DRAFT')
        ->json('expense.id');

    $this->actingAs($this->user)
        ->postJson('/api/v1/expenses/'.$expenseId.'/post')
        ->assertStatus(422)
        ->assertJsonPath('message', 'A supporting document is required before this expense can be posted.');
});

test('paid expense posts a balanced embedded journal using selected expense and payment accounts', function () {
    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', expensePayload($this))
        ->assertCreated()
        ->assertJsonPath('expense.status', 'DRAFT')
        ->assertJsonPath('expense.total_amount', 12000)
        ->json('expense.id');

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')
        ->assertOk()
        ->assertJsonPath('expense.status', 'POSTED')
        ->assertJsonPath('expense.payment_status', 'PAID');

    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'POST')->with('lines')->firstOrFail();

    expect((float) $journal->lines->sum('debit'))->toBe(12000.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(12000.0)
        ->and($journal->branch_id)->toBe($this->user->default_branch_id)
        ->and((float) $journal->lines->firstWhere('account_code', '6101')->debit)->toBe(10000.0)
        ->and((float) $journal->lines->firstWhere('account_code', '6102')->debit)->toBe(2000.0)
        ->and((float) $journal->lines->firstWhere('account_code', '1002')->credit)->toBe(12000.0);
});

test('unpaid expense posts to accounts payable then mark paid clears the payable', function () {
    $payload = expensePayload($this, [
        'payment_status' => 'UNPAID',
        'payment_account_id' => null,
        'payment_method' => null,
        'lines' => [['account_id' => $this->utilities->id, 'description' => 'Electric bill', 'amount' => 3500]],
    ]);
    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', $payload)->assertCreated()->json('expense.id');
    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk()->assertJsonPath('expense.payment_status', 'UNPAID');

    $post = AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'POST')->with('lines')->firstOrFail();
    expect((float) $post->lines->firstWhere('account_code', '2001')->credit)->toBe(3500.0);

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/mark-paid', [
        'payment_account_id' => $this->bank->id,
        'payment_method' => 'Bank Transfer',
        'payment_reference' => 'BANK-001',
    ])->assertOk()->assertJsonPath('expense.payment_status', 'PAID');

    $payment = AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'PAYMENT')->with('lines')->firstOrFail();
    expect((float) $payment->lines->firstWhere('account_code', '2001')->debit)->toBe(3500.0)
        ->and((float) $payment->lines->firstWhere('account_code', '1002')->credit)->toBe(3500.0);
});

test('posted expense cannot be directly edited and reversal preserves the original with a reversing journal', function () {
    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', expensePayload($this))->assertCreated()->json('expense.id');
    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk();

    $this->actingAs($this->user)->putJson('/api/v1/expenses/'.$expenseId, expensePayload($this, ['vendor_payee' => 'Changed']))
        ->assertStatus(422);

    $request = $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('DUPLICATE_EXPENSE'), 'reason' => 'Duplicate vendor invoice entered in error.'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING')
        ->assertJsonPath('expense.status', 'POSTED');

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('DUPLICATE_EXPENSE'), 'reason' => 'A second reversal must wait for the pending approval.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This expense already has a pending reversal request. Approve or reject it before creating another request.');

    expect(AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'REVERSE')->count())->toBe(0);

    $reversalRequestId = (int) $request->json('reversal_request.id');
    $this->actingAs($this->user)->postJson('/api/v1/expenses/reversal-requests/'.$reversalRequestId.'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED')
        ->assertJsonPath('expense.status', 'REVERSED');

    $expense = Expense::query()->findOrFail($expenseId);
    $reversal = AccountingJournalEntry::query()->where('source_type', 'EXPENSE')->where('source_id', $expenseId)->where('source_event', 'REVERSE')->with('lines')->firstOrFail();

    expect($expense->vendor_payee)->toBe('Sample Landlord')
        ->and((float) $reversal->lines->sum('debit'))->toBe(12000.0)
        ->and((float) $reversal->lines->sum('credit'))->toBe(12000.0)
        ->and((float) $reversal->lines->firstWhere('account_code', '1002')->debit)->toBe(12000.0)
        ->and((float) $reversal->lines->firstWhere('account_code', '6101')->credit)->toBe(10000.0);
});

test('posted prepaid rent appears on the balance sheet and paid cash flow instead of the income statement', function () {
    $prepaidRent = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '1301')->firstOrFail();

    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', expensePayload($this, [
        'description' => 'Advance rent payment',
        'lines' => [['account_id' => $prepaidRent->id, 'description' => 'Prepaid rent', 'amount' => 5000]],
    ]))->assertCreated()->json('expense.id');

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk();

    $this->actingAs($this->user)->getJson('/api/v1/accounting/financial-statements?from=2026-09-01&to=2026-09-30&year=2026')
        ->assertOk()
        ->assertJsonFragment(['code' => '1301', 'name' => 'Prepaid Rent', 'amount' => 5000]);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-flow?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertJsonPath('summary.operating', -5000);
});

test('expense log can filter rows by fund source', function () {
    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', expensePayload($this))
        ->assertCreated()
        ->json('expense.id');

    $this->actingAs($this->user)->getJson('/api/v1/expenses?funding_account_id='.$this->bank->id)
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.id', $expenseId);

    $this->actingAs($this->user)->getJson('/api/v1/expenses?funding_account_id='.$this->cash->id)
        ->assertOk()
        ->assertJsonCount(0, 'rows');
});
