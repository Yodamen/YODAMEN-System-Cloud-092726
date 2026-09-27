<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveRequest extends Model
{
    use HasFactory;

    protected $table = 'leave_requests';

    protected $fillable = [
        'business_id', 'code', 'personnel_id', 'request_type', 'leave_type_id',
        'start_date', 'end_date', 'duration_type', 'approved_units', 'paid_leave_approved', 'reason',
        'status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_reason_reference_id',
        'review_notes', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'approved_units' => 'decimal:2',
            'paid_leave_approved' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
