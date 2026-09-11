<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'sequence_key',
        'prefix',
        'next_number',
        'pad_length',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
