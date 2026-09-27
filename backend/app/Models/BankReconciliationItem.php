<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationItem extends Model
{
    use HasFactory;
    protected $fillable = ['bank_reconciliation_id','line_no','item_type','effect_on','description','reference','amount','adjustment_account_id','journal_entry_id'];
    protected function casts(): array { return ['amount'=>'decimal:2']; }
    public function reconciliation(): BelongsTo { return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id'); }
    public function adjustmentAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'adjustment_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
}
