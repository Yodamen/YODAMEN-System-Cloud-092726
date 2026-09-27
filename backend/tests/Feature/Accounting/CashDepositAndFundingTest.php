<?php

use App\Models\AccountingJournalEntry;
use App\Models\BusinessFundingTransaction;
use App\Models\CashDeposit;
use App\Models\ChartOfAccount;
use App\Services\Accounting\AccountingJournalService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->cash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_ON_HAND')->firstOrFail();
    $this->bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();
});


test('cash deposit requires supporting proof before posting', function () {
    $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-deposits', [
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'deposit_date' => '2026-09-26',
        'source_cash_account_id' => $this->cash->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 100,
        'reference' => 'NO-PROOF',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['attachment_tokens']);
});

test('cash deposits track recorded customer cash and transfer cash on hand to bank without revenue', function () {
    app(AccountingJournalService::class)->postEntry(
        $this->user->business_id,
        $this->user->default_branch_id,
        '2026-09-26',
        'PAYMENT',
        999001,
        'RECEIVE',
        'PAY-TEST-CASH',
        'Test customer cash receipt',
        [
            ['account_id' => $this->cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 0, 'credit' => 1000],
        ],
        $this->user->id,
    );

    $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/cash-deposits/day-summary?branch_id='.$this->user->default_branch_id.'&business_date=2026-09-26&source_cash_account_id='.$this->cash->id)
        ->assertOk()
        ->assertJsonPath('summary.expected_cash_sales', 1000)
        ->assertJsonPath('summary.remaining_to_deposit', 1000);

    $response = $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-deposits', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'cash-deposits')],
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'deposit_date' => '2026-09-26',
        'source_cash_account_id' => $this->cash->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 1000,
        'reference' => 'DEP-SLIP-001',
    ])->assertCreated();

    $deposit = CashDeposit::query()->findOrFail($response->json('deposit.id'));
    $journal = AccountingJournalEntry::query()->where('source_type', 'CASH_DEPOSIT')->where('source_id', $deposit->id)->where('source_event', 'POST')->with('lines.account')->firstOrFail();
    expect((float) $journal->lines->firstWhere('account_id', $this->bank->id)->debit)->toBe(1000.0)
        ->and((float) $journal->lines->firstWhere('account_id', $this->cash->id)->credit)->toBe(1000.0)
        ->and($journal->lines->whereIn('account.system_key', ['SERVICE_REVENUE','RETAIL_PRODUCT_SALES','PACKAGE_REVENUE'])->count())->toBe(0);

    $reversalRequest = $this->actingAs($this->user)
        ->postJson('/api/v1/accounting/cash-deposits/'.$deposit->id.'/reverse', ['reason_reference_id' => $this->workflowReasonId('ACCIDENTAL_ENTRY'), 'reason' => 'Bank rejected deposit slip'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING');

    expect($deposit->fresh()->status)->toBe('POSTED')
        ->and(AccountingJournalEntry::query()->where('source_type', 'CASH_DEPOSIT')->where('source_id', $deposit->id)->where('source_event', 'REVERSE')->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->postJson('/api/v1/accounting/cash-deposits/reversal-requests/'.$reversalRequest->json('reversal_request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED');

    expect($deposit->fresh()->status)->toBe('REVERSED')
        ->and(AccountingJournalEntry::query()->where('source_type', 'CASH_DEPOSIT')->where('source_id', $deposit->id)->where('source_event', 'REVERSE')->exists())->toBeTrue();
});

test('post go live owner funding records capital or owner loan without treating the cash as revenue', function () {
    $capital = $this->actingAs($this->user)->postJson('/api/v1/accounting/business-funding', [
        'branch_id' => $this->user->default_branch_id,
        'funding_date' => '2026-09-26',
        'funding_type' => 'CAPITAL_CONTRIBUTION',
        'cash_account_id' => $this->bank->id,
        'amount' => 25000,
        'reference' => 'OWNER-CAPITAL-SEP',
    ])->assertCreated();

    $row = BusinessFundingTransaction::query()->findOrFail($capital->json('funding.id'));
    $journal = AccountingJournalEntry::query()->where('source_type', 'BUSINESS_FUNDING')->where('source_id', $row->id)->where('source_event', 'POST')->with('lines.account')->firstOrFail();
    expect((float) $journal->lines->firstWhere('account_id', $this->bank->id)->debit)->toBe(25000.0)
        ->and((float) $journal->lines->firstWhere('account.system_key', 'OWNERS_CAPITAL')->credit)->toBe(25000.0)
        ->and($journal->lines->where('account.account_type', 'INCOME')->count())->toBe(0);

    $loan = $this->actingAs($this->user)->postJson('/api/v1/accounting/business-funding', [
        'funding_date' => '2026-09-26',
        'funding_type' => 'OWNER_LOAN',
        'cash_account_id' => $this->bank->id,
        'amount' => 10000,
    ])->assertCreated();
    $loanJournal = AccountingJournalEntry::query()->where('source_type', 'BUSINESS_FUNDING')->where('source_id', $loan->json('funding.id'))->where('source_event', 'POST')->with('lines.account')->firstOrFail();
    expect((float) $loanJournal->lines->firstWhere('account.system_key', 'LOANS_PAYABLE_OWNER')->credit)->toBe(10000.0);

    $reversal = $this->actingAs($this->user)
        ->postJson('/api/v1/accounting/business-funding/'.$row->id.'/reverse', ['reason_reference_id' => $this->workflowReasonId('WRONG_ACCOUNT'), 'reason' => 'Capital contribution was posted to the wrong bank.'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING');

    expect($row->fresh()->status)->toBe('POSTED');

    $this->actingAs($this->user)
        ->postJson('/api/v1/accounting/business-funding/reversal-requests/'.$reversal->json('reversal_request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED');

    expect($row->fresh()->status)->toBe('REVERSED')
        ->and(AccountingJournalEntry::query()->where('source_type', 'BUSINESS_FUNDING')->where('source_id', $row->id)->where('source_event', 'REVERSE')->exists())->toBeTrue();
});

test('accounting daily closing blocks new journal posting until the day is reopened', function () {
    $this->actingAs($this->user)->postJson('/api/v1/accounting/daily-closing', [
        'branch_id' => $this->user->default_branch_id,
        'closing_date' => '2026-09-26',
        'notes' => 'End of day accounting lock',
    ])->assertOk();

    expect(fn () => app(AccountingJournalService::class)->postEntry(
        $this->user->business_id,
        $this->user->default_branch_id,
        '2026-09-26',
        'TEST_POST',
        99001,
        'POST',
        'LOCK-TEST',
        'Should be blocked',
        [
            ['account_id' => $this->bank->id, 'debit' => 100, 'credit' => 0],
            ['account_key' => 'OWNERS_CAPITAL', 'debit' => 0, 'credit' => 100],
        ],
        $this->user->id,
    ))->toThrow(DomainException::class, 'Accounting is closed for 2026-09-26');

    $closingId = \App\Models\AccountingDailyClosing::query()->value('id');
    $this->actingAs($this->user)->postJson('/api/v1/accounting/daily-closing/'.$closingId.'/reopen', ['reason' => 'Late approved entry'])->assertOk();

    $entry = app(AccountingJournalService::class)->postEntry(
        $this->user->business_id, $this->user->default_branch_id, '2026-09-26', 'TEST_POST', 99001, 'POST', 'LOCK-TEST', 'Allowed after reopen',
        [['account_id'=>$this->bank->id,'debit'=>100,'credit'=>0], ['account_key'=>'OWNERS_CAPITAL','debit'=>0,'credit'=>100]], $this->user->id,
    );
    expect($entry)->not->toBeNull();
});
