<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'queue_entry_id', 'booking_id', 'customer_id', 'customer_type',
        'visit_date', 'check_in_at', 'service_start_at', 'check_out_at', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'immutable_date',
            'check_in_at' => 'immutable_datetime',
            'service_start_at' => 'immutable_datetime',
            'check_out_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function queueEntry(): BelongsTo { return $this->belongsTo(QueueEntry::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function serviceLines(): HasMany { return $this->hasMany(VisitServiceLine::class)->orderBy('sort_order')->orderBy('id'); }
    public function retailLines(): HasMany { return $this->hasMany(VisitRetailLine::class)->orderBy('sort_order')->orderBy('id'); }
    public function completionEvents(): HasMany { return $this->hasMany(ServiceCompletionEvent::class); }

    public function isInService(): bool
    {
        return strtoupper((string) $this->status) === 'IN_SERVICE';
    }
}
