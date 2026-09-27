<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCreditUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'service_credit_id', 'package_enrollment_id', 'customer_id', 'visit_id',
        'visit_service_line_id', 'service_id', 'sessions_used', 'usage_date', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_action_request_id', 'processed_by',
    ];

    protected function casts(): array
    {
        return ['sessions_used' => 'integer', 'usage_date' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime'];
    }

    public function serviceCredit(): BelongsTo { return $this->belongsTo(ServiceCredit::class); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function processedBy(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
}
