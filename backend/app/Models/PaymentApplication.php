<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'application_key', 'payment_id', 'sale_id', 'target_booking_id', 'target_type', 'target_sale_item_id',
        'package_enrollment_id', 'opening_customer_receivable_id', 'source_sale_id', 'amount', 'allocation_order', 'status',
        'target_snapshot', 'created_by', 'reversed_by', 'reversal_reason', 'reversed_at', 'transaction_action_request_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocation_order' => 'integer',
            'target_snapshot' => 'array',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function targetBooking(): BelongsTo { return $this->belongsTo(Booking::class, 'target_booking_id'); }
    public function targetSaleItem(): BelongsTo { return $this->belongsTo(SaleItem::class, 'target_sale_item_id'); }
    public function sourceSale(): BelongsTo { return $this->belongsTo(Sale::class, 'source_sale_id'); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function openingCustomerReceivable(): BelongsTo { return $this->belongsTo(OpeningCustomerReceivable::class); }
}
