<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PackageEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'customer_id', 'service_id', 'service_name_snapshot', 'total_sessions',
        'package_price_snapshot', 'package_gross_price_snapshot', 'booking_id', 'booking_service_line_id', 'queue_entry_id', 'queue_service_line_id',
        'visit_id', 'visit_service_line_id', 'origin_sale_id', 'origin_sale_item_id', 'opening_balance_id',
        'sessions_completed_before_activation', 'status', 'expiry_date', 'activated_at', 'completed_at',
        'cancelled_at', 'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'total_sessions' => 'integer',
            'sessions_completed_before_activation' => 'integer',
            'package_price_snapshot' => 'decimal:2',
            'package_gross_price_snapshot' => 'decimal:2',
            'expiry_date' => 'date:Y-m-d',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function bookingServiceLine(): BelongsTo { return $this->belongsTo(BookingServiceLine::class); }
    public function queueEntry(): BelongsTo { return $this->belongsTo(QueueEntry::class); }
    public function queueServiceLine(): BelongsTo { return $this->belongsTo(QueueServiceLine::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function originSale(): BelongsTo { return $this->belongsTo(Sale::class, 'origin_sale_id'); }
    public function originSaleItem(): BelongsTo { return $this->belongsTo(SaleItem::class, 'origin_sale_item_id'); }
    public function openingBalance(): BelongsTo { return $this->belongsTo(AccountingOpeningBalance::class, 'opening_balance_id'); }
    public function openingReceivable(): HasOne { return $this->hasOne(OpeningCustomerReceivable::class); }
    public function serviceCredit(): HasOne { return $this->hasOne(ServiceCredit::class); }
    public function usages(): HasMany { return $this->hasMany(ServiceCreditUsage::class); }
    public function ledgerMovements(): HasMany { return $this->hasMany(ServiceCreditLedger::class); }
    public function paymentApplications(): HasMany { return $this->hasMany(PaymentApplication::class); }
}
