<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffTip extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'sale_id', 'booking_id', 'customer_id', 'personnel_id', 'payment_id',
        'payment_method_reference_id', 'received_cash_account_id', 'amount', 'received_method_snapshot', 'received_reference',
        'status', 'payout_account_id', 'payout_method_snapshot', 'payout_reference', 'payout_date', 'paid_by', 'paid_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payout_date' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'payment_method_reference_id'); }
    public function receivedCashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'received_cash_account_id'); }
    public function payoutAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'payout_account_id'); }
    public function paidByUser(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
}
