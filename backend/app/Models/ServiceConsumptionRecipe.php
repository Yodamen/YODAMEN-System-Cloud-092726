<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceConsumptionRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'service_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceConsumptionRecipeLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
