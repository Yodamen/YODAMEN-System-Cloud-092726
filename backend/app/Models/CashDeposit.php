<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDeposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'business_date', 'deposit_date', 'deposit_source', 'cash_remittance_id',
        'source_cash_account_id', 'bank_account_id', 'expected_cash_sales_snapshot', 'amount',
        'reference', 'notes', 'status', 'journal_entry_id', 'reversal_journal_entry_id',
        'created_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'deposit_date' => 'immutable_date',
            'expected_cash_sales_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashRemittance(): BelongsTo { return $this->belongsTo(CashRemittance::class, 'cash_remittance_id'); }
    public function sourceCashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'source_cash_account_id'); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'bank_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'reversal_journal_entry_id'); }
}
