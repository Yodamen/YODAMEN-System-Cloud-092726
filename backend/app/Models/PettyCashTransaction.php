<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id','branch_id','code','transaction_date','transaction_type','direction','counterpart_account_id',
        'amount','reference','payee','description','status','journal_entry_id','created_by','reversed_at','reversed_by','reversal_reason',
    ];

    protected function casts(): array
    {
        return ['transaction_date'=>'immutable_date','amount'=>'decimal:2','reversed_at'=>'immutable_datetime'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function counterpartAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'counterpart_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
