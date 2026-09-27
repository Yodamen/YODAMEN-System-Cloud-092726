<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingDailyClosing extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','branch_id','closing_date','status','notes','closed_at','closed_by','reopened_at','reopened_by','reopen_reason'];
    protected function casts(): array { return ['closing_date'=>'immutable_date','closed_at'=>'immutable_datetime','reopened_at'=>'immutable_datetime']; }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
}
