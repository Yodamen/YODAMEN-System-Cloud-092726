<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','branch_id','code','bank_account_id','statement_date','statement_ending_balance','book_balance_snapshot','adjusted_bank_balance','adjusted_book_balance','difference','status','notes','created_by','reconciled_by','reconciled_at'];
    protected function casts(): array { return ['statement_date'=>'immutable_date','statement_ending_balance'=>'decimal:2','book_balance_snapshot'=>'decimal:2','adjusted_bank_balance'=>'decimal:2','adjusted_book_balance'=>'decimal:2','difference'=>'decimal:2','reconciled_at'=>'immutable_datetime']; }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'bank_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reconciler(): BelongsTo { return $this->belongsTo(User::class, 'reconciled_by'); }
    public function items(): HasMany { return $this->hasMany(BankReconciliationItem::class)->orderBy('line_no'); }
}
