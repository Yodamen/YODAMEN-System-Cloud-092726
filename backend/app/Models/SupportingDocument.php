<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportingDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'module', 'entity_type', 'entity_id', 'purpose', 'upload_token', 'state',
        'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by', 'attached_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'size_bytes' => 'integer',
            'attached_at' => 'immutable_datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
