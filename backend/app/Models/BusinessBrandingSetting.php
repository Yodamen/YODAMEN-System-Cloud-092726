<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessBrandingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'system_name',
        'business_display_name',
        'subtitle',
        'logo_source',
        'logo_path',
        'logo_url',
        'font_family',
        'primary_color',
        'accent_color',
        'sidebar_color',
        'login_label',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
