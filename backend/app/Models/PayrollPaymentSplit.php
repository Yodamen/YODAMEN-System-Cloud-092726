<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPaymentSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'business_id',
        'funding_account_id',
        'amount',
        'reference',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'funding_account_id');
    }
}
