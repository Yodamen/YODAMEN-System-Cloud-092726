<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'rule_name', 'discount_code', 'branch_id', 'personnel_ids', 'applies_to',
        'customer_id', 'customer_type', 'min_lifetime_value', 'max_lifetime_value',
        'last_visit_operator', 'last_visit_days_min', 'last_visit_days_max',
        'item_scope', 'service_id', 'service_ids', 'service_category_reference_id', 'service_category_reference_ids',
        'calculation_type', 'rate_value', 'effective_from', 'effective_until', 'priority', 'stackable', 'status',
        'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_lifetime_value' => 'decimal:2',
            'max_lifetime_value' => 'decimal:2',
            'last_visit_days_min' => 'integer',
            'last_visit_days_max' => 'integer',
            'rate_value' => 'decimal:4',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'priority' => 'integer',
            'personnel_ids' => 'array',
            'service_ids' => 'array',
            'service_category_reference_ids' => 'array',
            'stackable' => 'boolean',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function serviceCategory(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'service_category_reference_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
