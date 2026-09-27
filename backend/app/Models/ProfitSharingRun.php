<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfitSharingRun extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','branch_id','code','period_start','period_end','basis','cost_basis','net_income_snapshot','distributable_profit','total_percentage','total_profit_share','status','notes','payment_account_id','created_by','approved_by','approved_at','paid_by','paid_at'];
    protected function casts(): array { return ['period_start'=>'immutable_date','period_end'=>'immutable_date','net_income_snapshot'=>'decimal:2','distributable_profit'=>'decimal:2','total_percentage'=>'decimal:4','total_profit_share'=>'decimal:2','approved_at'=>'immutable_datetime','paid_at'=>'immutable_datetime']; }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function lines(): HasMany { return $this->hasMany(ProfitSharingLine::class)->orderBy('id'); }
    public function paymentAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'payment_account_id'); }
}
