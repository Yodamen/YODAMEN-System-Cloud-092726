<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'package_enrollment_id', 'customer_id', 'service_id', 'service_name_snapshot',
        'sessions_purchased', 'sessions_used', 'sessions_remaining', 'status', 'expiry_date', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sessions_purchased' => 'integer',
            'sessions_used' => 'integer',
            'sessions_remaining' => 'integer',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function usages(): HasMany { return $this->hasMany(ServiceCreditUsage::class); }
    public function ledgerMovements(): HasMany { return $this->hasMany(ServiceCreditLedger::class); }
}
