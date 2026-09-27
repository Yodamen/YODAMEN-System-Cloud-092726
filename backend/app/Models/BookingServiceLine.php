<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingServiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'code',
        'service_id',
        'service_name_snapshot',
        'source_type',
        'source_reference_code',
        'package_enrollment_id',
        'service_credit_id',
        'quantity',
        'assigned_personnel_id',
        'notes',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedPersonnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'assigned_personnel_id');
    }

    public function packageEnrollment(): BelongsTo
    {
        return $this->belongsTo(PackageEnrollment::class);
    }

    public function serviceCredit(): BelongsTo
    {
        return $this->belongsTo(ServiceCredit::class);
    }

    public function visitServiceLines(): HasMany
    {
        return $this->hasMany(VisitServiceLine::class);
    }
}

