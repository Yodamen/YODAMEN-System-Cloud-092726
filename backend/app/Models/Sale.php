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
        'business_id', 'branch_id', 'code', 'visit_id', 'booking_id', 'customer_id', 'sale_channel', 'sale_date',
        'gross_amount', 'discount_amount', 'net_amount', 'discount_code_snapshot', 'promotion_codes_snapshot', 'advance_payments', 'checkout_payments', 'total_paid', 'applied_amount', 'outstanding_balance',
        'correction_adjustment_amount', 'refund_adjustment_amount', 'cash_refunded_amount', 'refund_due_amount', 'effective_net_amount', 'refund_status',
        'status', 'financial_finalized_at', 'reversed_at', 'reversed_by', 'reversal_action_request_id', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'immutable_datetime',
            'financial_finalized_at' => 'immutable_datetime',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'advance_payments' => 'decimal:2',
            'checkout_payments' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'correction_adjustment_amount' => 'decimal:2',
            'refund_adjustment_amount' => 'decimal:2',
            'cash_refunded_amount' => 'decimal:2',
            'refund_due_amount' => 'decimal:2',
            'effective_net_amount' => 'decimal:2',
            'promotion_codes_snapshot' => 'array',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function paymentApplications(): HasMany { return $this->hasMany(PaymentApplication::class); }
    public function actionRequests(): HasMany { return $this->hasMany(TransactionActionRequest::class); }
    public function refunds(): HasMany { return $this->hasMany(TransactionRefund::class); }
    public function reversalActionRequest(): BelongsTo { return $this->belongsTo(TransactionActionRequest::class, 'reversal_action_request_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }

    public function isPendingFinancialFinalization(): bool
    {
        return in_array(strtoupper((string) $this->status), ['FOR_CHECKOUT', 'PARTIALLY_PAID', 'AWAITING_CONFIRM'], true);
    }
}
