<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'branch_id', 'visit_id', 'visit_service_line_id',
        'service_completion_event_id', 'service_id', 'service_name_snapshot',
        'service_quantity', 'effective_service_quantity', 'total_cost', 'status', 'correction_status', 'last_transaction_action_request_id', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'service_quantity' => 'decimal:4',
            'effective_service_quantity' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function completionEvent(): BelongsTo { return $this->belongsTo(ServiceCompletionEvent::class, 'service_completion_event_id'); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function lines(): HasMany { return $this->hasMany(ServiceConsumptionLine::class)->orderBy('id'); }
}
