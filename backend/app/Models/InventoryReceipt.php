<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'branch_id', 'received_date', 'supplier_id', 'supplier_name', 'reference_no',
        'notes', 'status', 'total_cost', 'original_total_cost', 'delivery_fee', 'payment_status', 'payment_method_reference_id',
        'payment_method_snapshot', 'payment_reference', 'payment_splits', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'immutable_date',
            'total_cost' => 'decimal:2',
            'original_total_cost' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'payment_splits' => 'array',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'payment_method_reference_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function lines(): HasMany { return $this->hasMany(InventoryReceiptLine::class); }
    public function actionRequests(): HasMany { return $this->hasMany(InventoryReceiptActionRequest::class); }
    public function stockReturns(): HasMany { return $this->hasMany(InventoryStockReturn::class); }
}
