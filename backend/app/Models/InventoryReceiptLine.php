<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceiptLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_receipt_id', 'retail_product_id', 'purchase_quantity', 'purchase_uom', 'conversion_factor', 'purchase_unit_cost', 'quantity_received', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'purchase_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:6',
            'purchase_unit_cost' => 'decimal:2',
            'quantity_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo { return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id'); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
}
