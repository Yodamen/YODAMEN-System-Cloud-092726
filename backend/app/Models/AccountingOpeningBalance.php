<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingOpeningBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'cutover_date', 'cash_snapshot', 'inventory_snapshot', 'receivables_snapshot', 'packages_snapshot',
        'cash_total', 'inventory_total', 'receivables_total', 'deferred_package_total', 'offset_account_id', 'journal_entry_id', 'status',
        'notes', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'cutover_date' => 'immutable_date',
            'cash_snapshot' => 'array',
            'inventory_snapshot' => 'array',
            'receivables_snapshot' => 'array',
            'packages_snapshot' => 'array',
            'cash_total' => 'decimal:2',
            'inventory_total' => 'decimal:2',
            'receivables_total' => 'decimal:2',
            'deferred_package_total' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function offsetAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'offset_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
