<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecoveryAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_recovery_id', 'payroll_run_id', 'payroll_line_id', 'amount', 'status', 'created_by', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function recovery(): BelongsTo { return $this->belongsTo(PayrollRecovery::class, 'payroll_recovery_id'); }
    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function line(): BelongsTo { return $this->belongsTo(PayrollLine::class, 'payroll_line_id'); }
}
