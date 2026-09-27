<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'name', 'account_type', 'account_group', 'financial_statement_group', 'normal_balance',
        'posting_type', 'cash_flow_classification', 'is_opex', 'is_cash_account', 'is_funding_source',
        'system_key', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_opex' => 'boolean',
            'is_cash_account' => 'boolean',
            'is_funding_source' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'account_id');
    }
}
