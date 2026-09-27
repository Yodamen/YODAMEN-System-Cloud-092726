<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetailProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'sku', 'barcode', 'name', 'category', 'brand', 'unit', 'purchase_uom', 'purchase_to_basic_factor',
        'purchase_uom_cost', 'basic_uom_cost', 'selling_uom', 'selling_to_basic_factor', 'consumption_uom', 'consumption_to_basic_factor',
        'retail_available', 'stock_type', 'unit_cost', 'selling_price', 'reorder_point', 'commission_eligible',
        'status', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
            'purchase_to_basic_factor' => 'decimal:6',
            'purchase_uom_cost' => 'decimal:4',
            'basic_uom_cost' => 'decimal:4',
            'selling_to_basic_factor' => 'decimal:6',
            'consumption_to_basic_factor' => 'decimal:6',
            'retail_available' => 'boolean',
            'selling_price' => 'decimal:2',
            'reorder_point' => 'decimal:4',
            'commission_eligible' => 'boolean',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function stocks(): HasMany { return $this->hasMany(InventoryStock::class); }
    public function movements(): HasMany { return $this->hasMany(InventoryMovement::class); }
    public function suppliers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(Supplier::class, 'supplier_items')->withTimestamps(); }
    public function prices(): HasMany { return $this->hasMany(RetailProductPrice::class, 'retail_product_id'); }
    public function approvalRequests(): HasMany { return $this->hasMany(RetailProductApprovalRequest::class, 'retail_product_id'); }

    public function sellingPriceForDate(?string $date = null): float
    {
        $date = $date ?: now()->toDateString();
        $price = $this->prices()
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($price) return (float) $price->selling_price;
        if ($this->prices()->exists()) return 0.0;

        return (float) ($this->selling_price ?? 0);
    }
}
