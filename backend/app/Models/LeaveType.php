<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'name', 'is_paid', 'consumes_balance',
        'sort_order', 'active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'consumes_balance' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
