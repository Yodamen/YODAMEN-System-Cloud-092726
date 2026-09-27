<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionActionRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_action_request_id', 'sale_item_id', 'line_action',
        'original_snapshot', 'proposed_snapshot', 'refund_quantity',
        'refund_amount', 'return_to_stock',
    ];

    protected function casts(): array
    {
        return [
            'original_snapshot' => 'array',
            'proposed_snapshot' => 'array',
            'refund_quantity' => 'decimal:4',
            'refund_amount' => 'decimal:2',
            'return_to_stock' => 'boolean',
        ];
    }

    public function request(): BelongsTo { return $this->belongsTo(TransactionActionRequest::class, 'transaction_action_request_id'); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
}
