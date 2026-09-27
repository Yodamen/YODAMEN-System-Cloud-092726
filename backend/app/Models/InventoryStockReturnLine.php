<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_stock_return_id','inventory_receipt_line_id','retail_product_id',
        'quantity_returned','unit_cost_snapshot','line_total',
    ];

    protected function casts(): array
    {
        return ['quantity_returned'=>'decimal:4','unit_cost_snapshot'=>'decimal:4','line_total'=>'decimal:2'];
    }

    public function stockReturn(): BelongsTo { return $this->belongsTo(InventoryStockReturn::class, 'inventory_stock_return_id'); }
    public function receiptLine(): BelongsTo { return $this->belongsTo(InventoryReceiptLine::class, 'inventory_receipt_line_id'); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
}
