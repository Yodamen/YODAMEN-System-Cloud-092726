<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCompensation extends Model
{
    use HasFactory;

    protected $table = 'daily_compensations';

    protected $fillable = [
        'business_id', 'personnel_id', 'attendance_id', 'leave_request_id', 'work_date',
        'compensation_rule_snapshot', 'hourly_rate_snapshot', 'ot_entitled_snapshot',
        'ot_multiplier_snapshot', 'guaranteed_daily_pay_snapshot', 'scheduled_minutes',
        'worked_minutes', 'ot_minutes', 'paid_leave_units', 'paid_leave_minutes',
        'hourly_basic_pay', 'ot_pay', 'confirmed_commission', 'guaranteed_target',
        'guaranteed_top_up', 'base_compensation_component', 'gross_daily_projection',
        'status', 'payroll_run_id', 'payroll_line_id', 'recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'hourly_rate_snapshot' => 'decimal:2',
            'ot_entitled_snapshot' => 'boolean',
            'ot_multiplier_snapshot' => 'decimal:3',
            'guaranteed_daily_pay_snapshot' => 'decimal:2',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'ot_minutes' => 'integer',
            'paid_leave_units' => 'decimal:2',
            'paid_leave_minutes' => 'integer',
            'hourly_basic_pay' => 'decimal:2',
            'ot_pay' => 'decimal:2',
            'confirmed_commission' => 'decimal:2',
            'guaranteed_target' => 'decimal:2',
            'guaranteed_top_up' => 'decimal:2',
            'base_compensation_component' => 'decimal:2',
            'gross_daily_projection' => 'decimal:2',
            'recalculated_at' => 'immutable_datetime',
        ];
    }

    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function attendance(): BelongsTo { return $this->belongsTo(Attendance::class); }
    public function leaveRequest(): BelongsTo { return $this->belongsTo(HrLeaveRequest::class, 'leave_request_id'); }
}
