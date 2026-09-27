<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConsumptionRecipeLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_consumption_recipe_id',
        'retail_product_id',
        'quantity_per_service',
        'consumption_uom_snapshot',
        'consumption_to_basic_factor_snapshot',
        'basic_quantity_per_service',
        'basic_uom_snapshot',
        'unit_cost_snapshot',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_service' => 'decimal:4',
            'consumption_to_basic_factor_snapshot' => 'decimal:6',
            'basic_quantity_per_service' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ServiceConsumptionRecipe::class, 'service_consumption_recipe_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }
}
