<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'branch_id', 'retail_product_id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:4', 'reserved' => 'decimal:4'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
}
