<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'request_type',
        'reversal_of_id',
        'inventory_movement_id',
        'branch_id',
        'retail_product_id',
        'quantity_change',
        'reason',
        'reason_reference_id',
        'review_reason_reference_id',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:4',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'reason_reference_id');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
