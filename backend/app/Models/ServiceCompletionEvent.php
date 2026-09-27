<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceCompletionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'visit_id', 'visit_service_line_id', 'booking_id', 'customer_id', 'service_id',
        'assigned_personnel_id', 'service_name_snapshot', 'service_source', 'package_enrollment_id', 'service_credit_id', 'assigned_personnel_name_snapshot', 'quantity',
        'completed_at', 'status', 'last_transaction_action_request_id', 'created_by',
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
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function serviceCredit(): BelongsTo { return $this->belongsTo(ServiceCredit::class); }
    public function materialConsumption(): HasOne { return $this->hasOne(ServiceConsumption::class, 'service_completion_event_id'); }
}
