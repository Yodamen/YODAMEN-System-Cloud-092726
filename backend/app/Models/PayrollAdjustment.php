<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','code','personnel_id','effective_date','adjustment_type','amount','description','reason','status','payroll_run_id','payroll_line_id','reversal_of_id','created_by'];
    protected function casts(): array { return ['effective_date'=>'immutable_date','amount'=>'decimal:2']; }
    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
}
