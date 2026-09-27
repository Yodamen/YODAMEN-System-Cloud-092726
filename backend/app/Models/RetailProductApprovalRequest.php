<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailProductApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'retail_product_id',
        'request_type',
        'proposed_data',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected function casts(): array
    {
        return [
            'proposed_data' => 'array',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
