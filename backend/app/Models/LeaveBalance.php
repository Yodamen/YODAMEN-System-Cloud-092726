<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'personnel_id', 'leave_type_id', 'year',
        'entitled_days', 'adjustment_days', 'notes', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled_days' => 'decimal:2',
            'adjustment_days' => 'decimal:2',
        ];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
