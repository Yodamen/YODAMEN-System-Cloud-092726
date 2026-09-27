<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockReturnRefund extends Model
{
    use HasFactory;

    protected $fillable = ['inventory_stock_return_id','refund_date','receiving_account_id','amount','reference','created_by'];
    protected function casts(): array { return ['refund_date'=>'immutable_date','amount'=>'decimal:2']; }
    public function stockReturn(): BelongsTo { return $this->belongsTo(InventoryStockReturn::class, 'inventory_stock_return_id'); }
    public function receivingAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'receiving_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
