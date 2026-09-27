<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionRefundLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_refund_id', 'transaction_action_request_line_id', 'sale_item_id',
        'refund_quantity', 'requested_amount', 'economic_adjustment_amount',
        'cash_refund_amount', 'balance_reduction_amount', 'correction_refund_due_settlement_amount',
        'return_to_stock', 'stock_quantity_returned', 'item_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'refund_quantity' => 'decimal:4',
            'requested_amount' => 'decimal:2',
            'economic_adjustment_amount' => 'decimal:2',
            'cash_refund_amount' => 'decimal:2',
            'balance_reduction_amount' => 'decimal:2',
            'correction_refund_due_settlement_amount' => 'decimal:2',
            'return_to_stock' => 'boolean',
            'stock_quantity_returned' => 'decimal:4',
            'item_snapshot' => 'array',
        ];
    }

    public function refund(): BelongsTo { return $this->belongsTo(TransactionRefund::class, 'transaction_refund_id'); }
    public function requestLine(): BelongsTo { return $this->belongsTo(TransactionActionRequestLine::class, 'transaction_action_request_line_id'); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
}
