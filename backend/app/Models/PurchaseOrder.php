<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'business_id','code','branch_id','supplier_id','order_date','expected_delivery_date',
        'status','reference_no','notes','total_cost','created_by','submitted_by','approved_by',
        'cancelled_by','submitted_at','approved_at','cancelled_at','cancellation_reason',
    ];
    protected function casts(): array { return [
        'order_date'=>'date:Y-m-d','expected_delivery_date'=>'date:Y-m-d',
        'total_cost'=>'decimal:2','submitted_at'=>'datetime','approved_at'=>'datetime','cancelled_at'=>'datetime',
    ]; }
    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function receipts(): HasMany { return $this->hasMany(InventoryReceipt::class); }
}
