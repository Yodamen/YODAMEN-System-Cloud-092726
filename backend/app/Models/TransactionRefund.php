<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'sale_id', 'transaction_action_request_id', 'code',
        'adjustment_amount', 'cash_refund_amount', 'balance_reduction_amount',
        'correction_refund_due_settlement_amount', 'refund_method', 'refund_reference',
        'reason_code', 'remarks', 'status', 'processed_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_amount' => 'decimal:2',
            'cash_refund_amount' => 'decimal:2',
            'balance_reduction_amount' => 'decimal:2',
            'correction_refund_due_settlement_amount' => 'decimal:2',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function actionRequest(): BelongsTo { return $this->belongsTo(TransactionActionRequest::class, 'transaction_action_request_id'); }
    public function processedBy(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
    public function lines(): HasMany { return $this->hasMany(TransactionRefundLine::class); }
}
