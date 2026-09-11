<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRebooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'booking_id',
        'code',
        'old_booking_date',
        'old_booking_time',
        'new_booking_date',
        'new_booking_time',
        'reason',
        'rebooked_by',
        'rebooked_at',
    ];

    protected function casts(): array
    {
        return [
            'old_booking_date' => 'date:Y-m-d',
            'new_booking_date' => 'date:Y-m-d',
            'rebooked_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rebooked_by');
    }
}
