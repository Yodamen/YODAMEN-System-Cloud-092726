<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseReversalRequest extends Model
{
    use HasFactory;

    protected $fillable = ['business_id','code','expense_id','status','reason','reason_reference_id','review_reason_reference_id','requested_by','reviewed_by','reviewed_at','review_notes'];
    protected function casts(): array { return ['reviewed_at'=>'immutable_datetime']; }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function reasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'reason_reference_id');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
