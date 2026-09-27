<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'branch_id', 'count_date', 'scope_type', 'stock_type', 'category', 'categories_json', 'status', 'notes',
        'created_by', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'immutable_date',
            'categories_json' => 'array',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function lines(): HasMany { return $this->hasMany(InventoryCountLine::class); }
}
