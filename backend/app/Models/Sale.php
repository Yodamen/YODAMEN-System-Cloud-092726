<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'visit_id', 'booking_id', 'customer_id', 'sale_date',
        'gross_amount', 'advance_payments', 'checkout_payments', 'total_paid', 'outstanding_balance',
        'status', 'financial_finalized_at', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'immutable_datetime',
            'financial_finalized_at' => 'immutable_datetime',
            'gross_amount' => 'decimal:2',
            'advance_payments' => 'decimal:2',
            'checkout_payments' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    public function isPendingFinancialFinalization(): bool
    {
        return in_array(strtoupper((string) $this->status), ['FOR_CHECKOUT', 'PARTIALLY_PAID', 'AWAITING_CONFIRM'], true);
    }
}
