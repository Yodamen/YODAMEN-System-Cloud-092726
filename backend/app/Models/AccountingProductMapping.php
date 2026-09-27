<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingProductMapping extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','retail_product_id','inventory_account_id','service_cost_account_id','retail_cost_account_id'];
    public function product(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
    public function inventoryAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'inventory_account_id'); }
    public function serviceCostAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'service_cost_account_id'); }
    public function retailCostAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'retail_cost_account_id'); }
}
