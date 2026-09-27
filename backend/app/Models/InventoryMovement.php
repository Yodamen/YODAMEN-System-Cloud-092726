<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'retail_product_id', 'sale_id', 'sale_item_id',
        'transaction_action_request_id', 'transaction_action_request_line_id', 'source_service_consumption_line_id', 'source_inventory_movement_id', 'action_effect_key',
        'movement_type', 'quantity_change', 'quantity_before', 'quantity_after',
        'unit_cost_snapshot', 'reference_code', 'reason', 'created_by', 'movement_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:4',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
            'movement_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function sourceInventoryMovement(): BelongsTo { return $this->belongsTo(self::class, 'source_inventory_movement_id'); }
}
