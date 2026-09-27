<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'personnel_id',
        'branch_id',
        'attendance_date',
        'source',
        'workflow_status',
        'attendance_status',
        'time_in_at',
        'time_out_at',
        'worked_minutes',
        'ot_minutes',
        'original_time_in_at',
        'original_time_out_at',
        'approved_by',
        'approved_at',
        'adjustment_reason',
        'adjusted_by',
        'adjusted_at',
        'payroll_locked_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'time_in_at' => 'immutable_datetime',
            'time_out_at' => 'immutable_datetime',
            'worked_minutes' => 'integer',
            'ot_minutes' => 'integer',
            'original_time_in_at' => 'immutable_datetime',
            'original_time_out_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'adjusted_at' => 'immutable_datetime',
            'payroll_locked_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(AttendanceProof::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
