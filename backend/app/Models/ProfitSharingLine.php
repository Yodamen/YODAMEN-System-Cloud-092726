<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitSharingLine extends Model
{
    use HasFactory;
    protected $fillable = ['profit_sharing_run_id','personnel_id','personnel_code_snapshot','personnel_name_snapshot','percentage_snapshot','distributable_profit_snapshot','share_amount','status'];
    protected function casts(): array { return ['percentage_snapshot'=>'decimal:4','distributable_profit_snapshot'=>'decimal:2','share_amount'=>'decimal:2']; }
    public function run(): BelongsTo { return $this->belongsTo(ProfitSharingRun::class, 'profit_sharing_run_id'); }
    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
}
