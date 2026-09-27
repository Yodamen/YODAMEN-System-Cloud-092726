<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReceiptActionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id','code','inventory_receipt_id','action_type','status','reason','reason_reference_id','review_reason_reference_id',
        'requested_by','reviewed_by','reviewed_at','review_notes','supplier_refund_receivable',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'supplier_refund_receivable' => 'decimal:2'];
    }

    public function receipt(): BelongsTo { return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id'); }
    public function lines(): HasMany { return $this->hasMany(InventoryReceiptActionLine::class); }
    public function refunds(): HasMany { return $this->hasMany(InventoryReceiptActionRefund::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function reasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'reason_reference_id');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
