<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStockReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id','code','branch_id','supplier_id','inventory_receipt_id','return_date','reason','reason_reference_id','review_reason_reference_id','status',
        'total_value','ap_credit_amount','supplier_refund_receivable','requested_by','reviewed_by','reviewed_at','review_notes',
    ];

    protected function casts(): array
    {
        return [
            'return_date'=>'immutable_date','total_value'=>'decimal:2','ap_credit_amount'=>'decimal:2',
            'supplier_refund_receivable'=>'decimal:2','reviewed_at'=>'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function lines(): HasMany { return $this->hasMany(InventoryStockReturnLine::class, 'inventory_stock_return_id'); }
    public function refunds(): HasMany { return $this->hasMany(InventoryStockReturnRefund::class, 'inventory_stock_return_id'); }

    public function reasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'reason_reference_id');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
