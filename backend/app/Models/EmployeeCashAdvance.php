<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeCashAdvance extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','code','personnel_id','issued_date','original_amount','deduction_per_payroll','purpose','notes','status','created_by','approved_by','approved_at','closed_by','closed_at'];
    protected function casts(): array { return ['issued_date'=>'immutable_date','original_amount'=>'decimal:2','deduction_per_payroll'=>'decimal:2','approved_at'=>'immutable_datetime','closed_at'=>'immutable_datetime']; }
    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function ledger(): HasMany { return $this->hasMany(EmployeeCashAdvanceLedger::class, 'cash_advance_id')->orderBy('entry_date')->orderBy('id'); }
}
