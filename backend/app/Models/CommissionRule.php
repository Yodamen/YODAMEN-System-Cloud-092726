<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'rule_name',
        'branch_id',
        'applies_to',
        'commission_scope',
        'personnel_id',
        'personnel_ids',
        'position_reference_id',
        'service_id',
        'service_ids',
        'service_category_reference_id',
        'service_category_reference_ids',
        'retail_product_id',
        'retail_category',
        'calculation_type',
        'rate_percentage',
        'rate_value',
        'basis',
        'effective_from',
        'effective_until',
        'priority',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_percentage' => 'decimal:4',
            'rate_value' => 'decimal:4',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'priority' => 'integer',
            'personnel_ids' => 'array',
            'service_ids' => 'array',
            'service_category_reference_ids' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'personnel_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'position_reference_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function retailProduct(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class, 'retail_product_id');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'service_category_reference_id');
    }
}
