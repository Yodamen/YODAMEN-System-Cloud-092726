<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id', 'code', 'customer_id', 'booking_id', 'sale_id', 'visit_id', 'payment_type', 'amount',
        'payment_method_reference_id', 'payment_method_snapshot', 'reference_no', 'bank_account', 'payment_date',
        'status', 'idempotency_key', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'payment_method_reference_id'); }
}
