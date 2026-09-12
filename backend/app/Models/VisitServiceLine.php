<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VisitServiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'queue_service_line_id', 'booking_service_line_id', 'code', 'service_id', 'service_name_snapshot',
        'source_type', 'source_reference_code', 'quantity', 'assigned_personnel_id', 'assigned_personnel_name_snapshot',
        'status', 'started_at', 'completed_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'notes',
        'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function queueServiceLine(): BelongsTo { return $this->belongsTo(QueueServiceLine::class); }
    public function bookingServiceLine(): BelongsTo { return $this->belongsTo(BookingServiceLine::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function assignedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'assigned_personnel_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function completionEvent(): HasOne { return $this->hasOne(ServiceCompletionEvent::class); }

    public function isFinalized(): bool
    {
        return in_array(strtoupper((string) $this->status), ['COMPLETED', 'CANCELLED'], true);
    }
}
