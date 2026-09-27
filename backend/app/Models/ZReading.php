<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZReading extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'business_date', 'status', 'snapshot',
        'closed_at', 'closed_by', 'reopened_at', 'reopened_by', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'snapshot' => 'array',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function reopener(): BelongsTo { return $this->belongsTo(User::class, 'reopened_by'); }
}
