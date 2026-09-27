<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCreditLedger extends Model
{
    use HasFactory;

    protected $table = 'service_credit_ledger';

    protected $fillable = [
        'business_id', 'code', 'movement_key', 'service_credit_id', 'package_enrollment_id', 'customer_id',
        'service_id', 'movement_type', 'sessions', 'booking_id', 'queue_entry_id', 'visit_id',
        'visit_service_line_id', 'sale_id', 'sale_item_id', 'notes', 'processed_by',
    ];

    protected function casts(): array
    {
        return ['sessions' => 'integer'];
    }

    public function serviceCredit(): BelongsTo { return $this->belongsTo(ServiceCredit::class); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function queueEntry(): BelongsTo { return $this->belongsTo(QueueEntry::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function processedBy(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
}
