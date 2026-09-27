<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionActionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'sale_id', 'code', 'action_type', 'status',
        'reason_code', 'reason_label', 'reason_reference_id', 'review_reason_reference_id', 'remarks', 'original_snapshot', 'proposed_snapshot',
        'impact_preview', 'sale_version_hash', 'refund_method', 'refund_reference',
        'requested_refund_amount', 'requested_by', 'requested_at', 'reviewed_by',
        'reviewed_at', 'manager_remarks', 'posting_summary', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'original_snapshot' => 'array',
            'proposed_snapshot' => 'array',
            'impact_preview' => 'array',
            'posting_summary' => 'array',
            'requested_refund_amount' => 'decimal:2',
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function lines(): HasMany { return $this->hasMany(TransactionActionRequestLine::class)->orderBy('id'); }

    public function reasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'reason_reference_id');
    }

    public function reviewReasonReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'review_reason_reference_id');
    }

}
