<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncentiveRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id','code','rule_name','branch_id','metric','target_scope','personnel_id','personnel_ids','service_ids','service_category_reference_ids','threshold_value',
        'reward_type','reward_value','effective_from','effective_until','priority','stackable','status','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'threshold_value'=>'decimal:2','reward_value'=>'decimal:4','effective_from'=>'date:Y-m-d','effective_until'=>'date:Y-m-d',
            'priority'=>'integer','stackable'=>'boolean','personnel_ids'=>'array','service_ids'=>'array','service_category_reference_ids'=>'array',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
}
