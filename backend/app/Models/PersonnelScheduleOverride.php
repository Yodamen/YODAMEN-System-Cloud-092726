<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelScheduleOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'personnel_id', 'schedule_date', 'schedule_type', 'expected_minutes',
        'start_time', 'end_time', 'notes', 'source_type', 'source_reference_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'immutable_date',
            'expected_minutes' => 'integer',
        ];
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
