<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRemittance extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'business_date', 'remittance_date',
        'source_cash_account_id', 'custody_cash_account_id', 'received_by_user_id',
        'received_by_name_snapshot', 'expected_cash_sales_snapshot', 'amount',
        'reference', 'notes', 'status', 'journal_entry_id', 'reversal_journal_entry_id',
        'created_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'remittance_date' => 'immutable_date',
            'expected_cash_sales_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function sourceCashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'source_cash_account_id'); }
    public function custodyCashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'custody_cash_account_id'); }
    public function receivedByUser(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'reversal_journal_entry_id'); }
    public function deposits(): HasMany { return $this->hasMany(CashDeposit::class, 'cash_remittance_id'); }
}
