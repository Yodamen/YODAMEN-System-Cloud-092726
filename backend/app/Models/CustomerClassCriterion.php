<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerClassCriterion extends Model
{
    protected $fillable = [
        'business_id',
        'code',
        'name',
        'min_lifetime_value',
        'sort_order',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_lifetime_value' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
