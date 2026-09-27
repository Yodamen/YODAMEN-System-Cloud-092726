<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConsumptionLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_consumption_id', 'retail_product_id', 'inventory_movement_id',
        'item_name_snapshot', 'consumption_quantity', 'consumption_uom_snapshot',
        'consumption_to_basic_factor_snapshot', 'basic_quantity', 'basic_uom_snapshot',
        'weighted_average_basic_cost', 'cost_amount', 'quantity_before', 'quantity_after',
    ];

    protected function casts(): array
    {
        return [
            'consumption_quantity' => 'decimal:4',
            'consumption_to_basic_factor_snapshot' => 'decimal:6',
            'basic_quantity' => 'decimal:4',
            'weighted_average_basic_cost' => 'decimal:4',
            'cost_amount' => 'decimal:2',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
        ];
    }

    public function consumption(): BelongsTo { return $this->belongsTo(ServiceConsumption::class, 'service_consumption_id'); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
    public function movement(): BelongsTo { return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id'); }
}
