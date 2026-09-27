<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningCustomerReceivable extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'opening_balance_id', 'customer_id', 'package_enrollment_id',
        'code', 'balance_type', 'cutover_date', 'reference', 'original_amount', 'remaining_balance',
        'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cutover_date' => 'immutable_date',
            'original_amount' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function openingBalance(): BelongsTo { return $this->belongsTo(AccountingOpeningBalance::class, 'opening_balance_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function applications(): HasMany { return $this->hasMany(PaymentApplication::class, 'opening_customer_receivable_id'); }
}
