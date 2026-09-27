<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelWorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'personnel_id', 'day_of_week', 'is_working_day', 'expected_minutes',
        'start_time', 'end_time', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working_day' => 'boolean',
            'expected_minutes' => 'integer',
        ];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
