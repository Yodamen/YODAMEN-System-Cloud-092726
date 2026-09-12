<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCompletionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'visit_id', 'visit_service_line_id', 'booking_id', 'customer_id', 'service_id',
        'assigned_personnel_id', 'service_name_snapshot', 'assigned_personnel_name_snapshot', 'quantity',
        'completed_at', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function assignedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'assigned_personnel_id'); }
}
