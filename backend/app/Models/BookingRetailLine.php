<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRetailLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'code',
        'legacy_retail_item_code',
        'retail_product_id',
        'sku_snapshot',
        'item_name_snapshot',
        'quantity',
        'unit_price_snapshot',
        'assigned_personnel_id',
        'assigned_personnel_name_snapshot',
        'notes',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function retailProduct(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }

    public function assignedPersonnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'assigned_personnel_id');
    }
}
