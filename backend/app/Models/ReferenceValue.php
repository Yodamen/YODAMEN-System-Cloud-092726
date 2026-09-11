<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_group_id',
        'code',
        'display_value',
        'sort_order',
        'is_system',
        'active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReferenceGroup::class, 'reference_group_id');
    }
}
