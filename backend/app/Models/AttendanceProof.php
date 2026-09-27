<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'business_id',
        'proof_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'captured_at',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
