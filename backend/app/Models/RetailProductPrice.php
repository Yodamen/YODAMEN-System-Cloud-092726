<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailProductPrice extends Model
{
    protected $fillable = [
        'business_id', 'retail_product_id', 'selling_price', 'effective_from', 'effective_until', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }
}
