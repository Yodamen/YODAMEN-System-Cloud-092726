<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_count_id', 'retail_product_id', 'system_quantity_snapshot', 'actual_quantity',
        'variance_quantity', 'unit_cost_snapshot', 'line_notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity_snapshot' => 'decimal:4',
            'actual_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
        ];
    }

    public function count(): BelongsTo { return $this->belongsTo(InventoryCount::class, 'inventory_count_id'); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
}
