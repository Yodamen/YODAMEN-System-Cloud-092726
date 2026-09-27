<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReversalApprovalRequest extends Model
{
    protected $fillable = [
        'business_id','code','module','source_type','source_id','source_code','status','reason','reason_reference_id','review_reason_reference_id',
        'requested_by','requested_at','reviewed_by','reviewed_at','review_notes',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

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
