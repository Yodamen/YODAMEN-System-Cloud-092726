<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id','retail_product_id','purchase_quantity','purchase_uom',
        'conversion_factor','purchase_unit_cost','line_total',
    ];
    protected function casts(): array { return [
        'purchase_quantity'=>'decimal:4', 'conversion_factor'=>'decimal:6',
        'purchase_unit_cost'=>'decimal:2','line_total'=>'decimal:2',
    ]; }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class,'purchase_order_id'); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class,'retail_product_id'); }
    public function receiptLines(): HasMany { return $this->hasMany(InventoryReceiptLine::class); }
}
