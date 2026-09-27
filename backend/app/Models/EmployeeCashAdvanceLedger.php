<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCashAdvanceLedger extends Model
{
    use HasFactory;
    protected $table = 'employee_cash_advance_ledger';
    protected $fillable = ['business_id','cash_advance_id','entry_date','entry_type','amount','payroll_run_id','payroll_line_id','reversal_of_id','notes','created_by'];
    protected function casts(): array { return ['entry_date'=>'immutable_date','amount'=>'decimal:2']; }
    public function cashAdvance(): BelongsTo { return $this->belongsTo(EmployeeCashAdvance::class, 'cash_advance_id'); }
    public function processor(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
