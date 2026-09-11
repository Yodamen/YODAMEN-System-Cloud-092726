<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'service_category_reference_id',
        'service_count_reference_id',
        'number_of_sessions',
        'duration_minutes',
        'description',
        'status_reference_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'number_of_sessions' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'service_category_reference_id');
    }

    public function serviceCount(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'service_count_reference_id');
    }

    public function statusReference(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'status_reference_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }
}
