<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFundingTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'funding_date', 'funding_type', 'cash_account_id',
        'amount', 'reference', 'notes', 'status', 'journal_entry_id', 'reversal_journal_entry_id',
        'created_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'funding_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'reversal_journal_entry_id'); }
}
