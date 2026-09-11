<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Personnel extends Model
{
    use HasFactory;

    protected $table = 'personnel';

    protected $fillable = [
        'business_id',
        'code',
        'first_name',
        'last_name',
        'mobile_number',
        'email',
        'position_reference_id',
        'branch_id',
        'employment_type_reference_id',
        'personnel_type_reference_id',
        'profit_sharing_eligible',
        'profit_sharing_percentage',
        'profit_sharing_effective_from',
        'profit_sharing_effective_until',
        'hourly_rate',
        'ot_entitled',
        'ot_multiplier',
        'compensation_rule_reference_id',
        'guaranteed_daily_pay',
        'can_perform_services',
        'commission_eligible',
        'hire_date',
        'linked_user_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'profit_sharing_eligible' => 'boolean',
            'profit_sharing_percentage' => 'decimal:4',
            'profit_sharing_effective_from' => 'immutable_date',
            'profit_sharing_effective_until' => 'immutable_date',
            'hourly_rate' => 'decimal:2',
            'ot_entitled' => 'boolean',
            'ot_multiplier' => 'decimal:3',
            'guaranteed_daily_pay' => 'decimal:2',
            'can_perform_services' => 'boolean',
            'commission_eligible' => 'boolean',
            'hire_date' => 'immutable_date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'position_reference_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'employment_type_reference_id');
    }

    public function personnelType(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'personnel_type_reference_id');
    }

    public function compensationRule(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'compensation_rule_reference_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
