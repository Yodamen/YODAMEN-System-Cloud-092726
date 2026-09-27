<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceiptActionLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_receipt_action_request_id','inventory_receipt_line_id',
        'before_purchase_quantity','before_purchase_unit_cost','corrected_purchase_quantity',
        'corrected_purchase_unit_cost','quantity_delta','value_delta',
    ];

    protected function casts(): array
    {
        return [
            'before_purchase_quantity'=>'decimal:4','before_purchase_unit_cost'=>'decimal:2',
            'corrected_purchase_quantity'=>'decimal:4','corrected_purchase_unit_cost'=>'decimal:2',
            'quantity_delta'=>'decimal:4','value_delta'=>'decimal:2',
        ];
    }

    public function request(): BelongsTo { return $this->belongsTo(InventoryReceiptActionRequest::class, 'inventory_receipt_action_request_id'); }
    public function receiptLine(): BelongsTo { return $this->belongsTo(InventoryReceiptLine::class, 'inventory_receipt_line_id'); }
}
