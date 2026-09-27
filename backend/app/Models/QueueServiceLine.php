<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueServiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'queue_entry_id', 'booking_service_line_id', 'code', 'service_id', 'service_name_snapshot',
        'source_type', 'source_reference_code', 'package_enrollment_id', 'service_credit_id', 'quantity', 'assigned_personnel_id',
        'assigned_personnel_name_snapshot', 'status', 'notes', 'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    public function queueEntry(): BelongsTo { return $this->belongsTo(QueueEntry::class); }
    public function bookingServiceLine(): BelongsTo { return $this->belongsTo(BookingServiceLine::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function assignedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'assigned_personnel_id'); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function serviceCredit(): BelongsTo { return $this->belongsTo(ServiceCredit::class); }
}
