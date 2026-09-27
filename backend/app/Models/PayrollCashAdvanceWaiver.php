<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollCashAdvanceWaiver extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'payroll_run_id', 'payroll_line_id', 'cash_advance_id',
        'scheduled_amount', 'waived_amount', 'status', 'reason', 'waived_by',
        'waived_at', 'revoked_by', 'revoked_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'waived_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function line(): BelongsTo { return $this->belongsTo(PayrollLine::class, 'payroll_line_id'); }
    public function cashAdvance(): BelongsTo { return $this->belongsTo(EmployeeCashAdvance::class, 'cash_advance_id'); }
    public function waivedBy(): BelongsTo { return $this->belongsTo(User::class, 'waived_by'); }
    public function revokedBy(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by'); }
}
