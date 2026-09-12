<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QueueEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'booking_id', 'customer_id', 'customer_type',
        'queue_date', 'check_in_at', 'source', 'status', 'booking_created_at', 'scheduled_date',
        'scheduled_time', 'notes', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'immutable_date',
            'check_in_at' => 'immutable_datetime',
            'booking_created_at' => 'immutable_datetime',
            'scheduled_date' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function serviceLines(): HasMany { return $this->hasMany(QueueServiceLine::class)->orderBy('sort_order')->orderBy('id'); }
    public function retailLines(): HasMany { return $this->hasMany(QueueRetailLine::class)->orderBy('sort_order')->orderBy('id'); }
    public function visit(): HasOne { return $this->hasOne(Visit::class); }

    public function isWaiting(): bool
    {
        return strtoupper((string) $this->status) === 'WAITING';
    }
}
