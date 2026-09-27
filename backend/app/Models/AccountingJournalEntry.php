<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingJournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'posting_date', 'source_type', 'source_id', 'source_event',
        'reference', 'description', 'status', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }
}
