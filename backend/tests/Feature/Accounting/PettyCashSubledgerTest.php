<?php

use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\PettyCashLiquidation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->petty = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'PETTY_CASH')->firstOrFail();
    $this->bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();
    $this->supplies = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('code', '6301')->firstOrFail();
});

test('petty cash is replenishment only and automatically shows posted expense cash-outs', function () {
    $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => '2026-09-25',
        'transaction_type' => 'REPLENISHMENT',
        'direction' => 'IN',
        'funding_account_id' => $this->bank->id,
        'amount' => 1000,
        'reference' => 'PC-REP-001',
    ])->assertCreated();

    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'expenses')],
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => '2026-09-25',
        'vendor_payee' => 'Office Supply Store',
        'reference' => 'OR-PC-001',
        'description' => 'Supplies paid from petty cash',
        'payment_status' => 'PAID',
        'payment_splits' => [[
            'funding_account_id' => $this->petty->id,
            'amount' => 300,
            'reference' => 'PC-OUT-001',
        ]],
        'lines' => [[
            'account_id' => $this->supplies->id,
            'description' => 'Supplies',
            'amount' => 300,
        ]],
    ])->assertCreated()->json('expense.id');

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk();

    $response = $this->actingAs($this->user)->getJson('/api/v1/petty-cash?from=2026-09-01&to=2026-09-30&branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('summary.balance', 700)
        ->assertJsonPath('summary.replenished', 1000)
        ->assertJsonPath('summary.disbursed', 300)
        ->assertJsonPath('summary.unliquidated_outflow', 300)
        ->assertJsonPath('rows.0.source_label', 'Expense')
        ->assertJsonPath('rows.0.out', 300);

    expect(collect($response->json('rows'))->pluck('source_label')->all())->toContain('Replenishment');

    $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => '2026-09-25',
        'transaction_type' => 'DISBURSEMENT',
        'direction' => 'OUT',
        'counterpart_account_id' => $this->supplies->id,
        'amount' => 50,
    ])->assertStatus(422);
});

test('liquidation reconciles book to actual cash without reposting expense journals', function () {
    $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => '2026-09-25',
        'funding_account_id' => $this->bank->id,
        'amount' => 1000,
    ])->assertCreated();

    $journalCount = AccountingJournalEntry::query()->count();

    $this->actingAs($this->user)->getJson('/api/v1/petty-cash/liquidation-preview?branch_id='.$this->user->default_branch_id.'&liquidation_date=2026-09-25')
        ->assertOk()
        ->assertJsonPath('preview.book_balance', 1000)
        ->assertJsonPath('preview.covered_inflow', 1000);

    $this->actingAs($this->user)->postJson('/api/v1/petty-cash/liquidations', [
        'branch_id' => $this->user->default_branch_id,
        'liquidation_date' => '2026-09-25',
        'actual_cash_count' => 1000,
        'notes' => 'Cash count agrees with the ledger.',
    ])->assertCreated()
        ->assertJsonPath('liquidation.reconciliation_status', 'BALANCED')
        ->assertJsonPath('liquidation.variance_amount', 0);

    expect(AccountingJournalEntry::query()->count())->toBe($journalCount)
        ->and(PettyCashLiquidation::query()->count())->toBe(1);
});

test('petty cash reversal requires approval and reversed replenishments are separated from active summary', function () {
    $created = $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => '2026-09-25',
        'transaction_type' => 'REPLENISHMENT',
        'direction' => 'IN',
        'funding_account_id' => $this->bank->id,
        'amount' => 1000,
        'reference' => 'PC-REV-001',
    ])->assertCreated();

    $transactionId = $created->json('transaction.id');
    $request = $this->actingAs($this->user)
        ->postJson('/api/v1/petty-cash/'.$transactionId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('WRONG_PAYMENT_SOURCE'), 'reason' => 'Replenishment posted to the wrong source account.'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING');

    $this->actingAs($this->user)
        ->postJson('/api/v1/petty-cash/'.$transactionId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('ACCIDENTAL_ENTRY'), 'reason' => 'A second reversal must not be accepted while the first is pending.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This record already has a pending reversal request. Approve or reject it before creating another request.');

    $beforeApproval = $this->actingAs($this->user)
        ->getJson('/api/v1/petty-cash?from=2026-09-01&to=2026-09-30&branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('summary.balance', 1000)
        ->assertJsonPath('summary.replenished', 1000)
        ->assertJsonPath('summary.reversed', 0);

    expect(collect($beforeApproval->json('rows'))->firstWhere('source_id', $transactionId)['pending_reversal']['status'] ?? null)->toBe('PENDING')
        ->and(AccountingJournalEntry::query()->where('source_type', 'PETTY_CASH')->where('source_id', $transactionId)->where('source_event', 'REVERSAL')->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->postJson('/api/v1/petty-cash/reversal-requests/'.$request->json('reversal_request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED');

    $this->actingAs($this->user)
        ->getJson('/api/v1/petty-cash?from=2026-09-01&to=2026-09-30&branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('summary.balance', 0)
        ->assertJsonPath('summary.replenished', 0)
        ->assertJsonPath('summary.disbursed', 0)
        ->assertJsonPath('summary.reversed', 1000);
});

test('reversed petty cash funded expenses are excluded from active disbursed summary', function () {
    $this->actingAs($this->user)->postJson('/api/v1/petty-cash', [
        'branch_id' => $this->user->default_branch_id,
        'transaction_date' => '2026-09-25',
        'funding_account_id' => $this->bank->id,
        'amount' => 1000,
    ])->assertCreated();

    $expenseId = $this->actingAs($this->user)->postJson('/api/v1/expenses', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'expenses')],
        'branch_id' => $this->user->default_branch_id,
        'expense_date' => '2026-09-25',
        'vendor_payee' => 'Office Supply Store',
        'description' => 'Reversible petty cash expense',
        'payment_status' => 'PAID',
        'payment_splits' => [[
            'funding_account_id' => $this->petty->id,
            'amount' => 300,
        ]],
        'lines' => [[
            'account_id' => $this->supplies->id,
            'description' => 'Supplies',
            'amount' => 300,
        ]],
    ])->assertCreated()->json('expense.id');

    $this->actingAs($this->user)->postJson('/api/v1/expenses/'.$expenseId.'/post')->assertOk();

    $reversalRequestId = $this->actingAs($this->user)
        ->postJson('/api/v1/expenses/'.$expenseId.'/reverse', ['reason_reference_id' => $this->workflowReasonId('VOIDED_EXPENSE'), 'reason' => 'Expense receipt was posted in error.'])
        ->assertCreated()
        ->json('reversal_request.id');

    $this->actingAs($this->user)
        ->postJson('/api/v1/expenses/reversal-requests/'.$reversalRequestId.'/approve')
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/petty-cash?from=2026-09-01&to=2026-09-30&branch_id='.$this->user->default_branch_id)
        ->assertOk()
        ->assertJsonPath('summary.balance', 1000)
        ->assertJsonPath('summary.replenished', 1000)
        ->assertJsonPath('summary.disbursed', 0)
        ->assertJsonPath('summary.reversed', 300);
});
