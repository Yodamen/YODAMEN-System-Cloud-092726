<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PayrollCashAdvanceAllocation extends Model { use HasFactory; protected $fillable=['payroll_run_id','payroll_line_id','cash_advance_id','amount','status','ledger_entry_id']; protected function casts():array{return['amount'=>'decimal:2'];} public function line():BelongsTo{return $this->belongsTo(PayrollLine::class,'payroll_line_id');} public function cashAdvance():BelongsTo{return $this->belongsTo(EmployeeCashAdvance::class,'cash_advance_id');} }
