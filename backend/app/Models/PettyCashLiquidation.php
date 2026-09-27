<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashLiquidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'liquidation_date', 'period_from', 'period_to',
        'through_journal_entry_id', 'book_balance', 'actual_cash_count', 'variance_amount',
        'covered_outflow', 'reconciliation_status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'liquidation_date' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'book_balance' => 'decimal:2',
            'actual_cash_count' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'covered_outflow' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
