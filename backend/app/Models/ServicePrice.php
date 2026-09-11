<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'service_id',
        'code',
        'scope',
        'branch_id',
        'standard_price',
        'effective_from',
        'effective_until',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'standard_price' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
