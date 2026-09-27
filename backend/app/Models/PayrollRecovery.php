<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRecovery extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'personnel_id', 'recovery_type',
        'source_commission_earning_id', 'source_adjustment_earning_id', 'source_transaction_action_request_id',
        'original_payroll_run_id', 'original_payroll_line_id', 'origin_work_date', 'source_label',
        'recovery_amount', 'applied_amount', 'remaining_amount', 'status', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'origin_work_date' => 'immutable_date',
            'recovery_amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function sourceEarning(): BelongsTo { return $this->belongsTo(CommissionEarning::class, 'source_commission_earning_id'); }
    public function sourceAdjustment(): BelongsTo { return $this->belongsTo(CommissionEarning::class, 'source_adjustment_earning_id'); }
    public function sourceAction(): BelongsTo { return $this->belongsTo(TransactionActionRequest::class, 'source_transaction_action_request_id'); }
    public function originalPayrollRun(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'original_payroll_run_id'); }
    public function originalPayrollLine(): BelongsTo { return $this->belongsTo(PayrollLine::class, 'original_payroll_line_id'); }
    public function allocations(): HasMany { return $this->hasMany(PayrollRecoveryAllocation::class); }
}
