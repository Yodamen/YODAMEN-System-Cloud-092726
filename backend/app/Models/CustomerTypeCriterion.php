<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTypeCriterion extends Model
{
    protected $fillable = [
        'business_id',
        'code',
        'name',
        'color',
        'min_completed_visits',
        'sort_order',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_completed_visits' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
