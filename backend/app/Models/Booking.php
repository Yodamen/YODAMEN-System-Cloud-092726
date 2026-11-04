<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    public function save(array $options = [])
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            $this->trial_counted = app(\App\Services\Subscriptions\TrialUsageService::class)
                ->reserve((int) $this->business_id, null);
            if (! parent::save($options)) {
                throw new \RuntimeException('The record was not saved. Trial usage was rolled back.');
            }

            return true;
        });
    }

    protected $fillable = [
        'business_id',
        'branch_id',
        'code',
        'customer_id',
        'customer_type',
        'booking_date',
        'booking_time',
        'booking_source',
        'status',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'trial_counted' => 'boolean',
            'booking_date' => 'date:Y-m-d',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceLines(): HasMany
    {
        return $this->hasMany(BookingServiceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function retailLines(): HasMany
    {
        return $this->hasMany(BookingRetailLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rebookings(): HasMany
    {
        return $this->hasMany(BookingRebooking::class)->orderByDesc('rebooked_at');
    }

    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class);
    }

    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function packageEnrollments(): HasMany
    {
        return $this->hasMany(PackageEnrollment::class);
    }

    public function canCheckIn(): bool
    {
        return strtoupper((string) $this->status) === 'BOOKED'
            && strtoupper((string) $this->booking_source) === 'RESERVATION';
    }

    public function isEditableFromBookings(): bool
    {
        return strtoupper($this->status) === 'BOOKED';
    }
}
