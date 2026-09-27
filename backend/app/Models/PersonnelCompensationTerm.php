<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelCompensationTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'personnel_id', 'effective_from', 'effective_until',
        'compensation_rule_reference_id', 'hourly_rate', 'ot_entitled',
        'ot_multiplier', 'guaranteed_daily_pay', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'hourly_rate' => 'decimal:2',
            'ot_entitled' => 'boolean',
            'ot_multiplier' => 'decimal:3',
            'guaranteed_daily_pay' => 'decimal:2',
        ];
    }

    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function compensationRule(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'compensation_rule_reference_id'); }
}
