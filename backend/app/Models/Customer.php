<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'first_name',
        'last_name',
        'mobile_number',
        'mobile_normalized',
        'email',
        'birthday',
        'gender_reference_id',
        'address',
        'lead_source_reference_id',
        'registration_date',
        'status',
        'is_test_customer',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date:Y-m-d',
            'registration_date' => 'date:Y-m-d',
            'is_test_customer' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'gender_reference_id');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'lead_source_reference_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->orderByDesc('visit_date')->orderByDesc('id');
    }

    public function packageEnrollments(): HasMany
    {
        return $this->hasMany(PackageEnrollment::class);
    }

    public function serviceCredits(): HasMany
    {
        return $this->hasMany(ServiceCredit::class);
    }

    public function getSegmentLabelAttribute(): string
    {
        $completedVisits = $this->relationLoaded('visits')
            ? $this->visits->filter(fn ($visit) => strtoupper((string) $visit->status) === 'COMPLETED')->count()
            : $this->visits()->where('status', 'COMPLETED')->count();

        return app(\App\Services\Customers\CustomerClassificationService::class)
            ->typeFor((int) $this->business_id, $completedVisits)['name'];
    }
}
