<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'sale_id', 'sale_item_id', 'visit_id', 'visit_service_line_id', 'visit_retail_line_id', 'earning_type',
        'booking_id', 'customer_id', 'service_id', 'personnel_id', 'commission_rule_id', 'incentive_rule_id', 'work_date',
        'service_name_snapshot', 'personnel_name_snapshot', 'basis', 'basis_amount',
        'calculation_type', 'rate_value', 'commission_amount', 'status',
        'payroll_run_id', 'payroll_line_id', 'source_transaction_action_request_id', 'source_commission_earning_id', 'transaction_action_effect_key', 'reversal_reason', 'reversed_at', 'reversed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'basis_amount' => 'decimal:2',
            'rate_value' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function personnel(): BelongsTo { return $this->belongsTo(Personnel::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function rule(): BelongsTo { return $this->belongsTo(CommissionRule::class, 'commission_rule_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function visitRetailLine(): BelongsTo { return $this->belongsTo(VisitRetailLine::class); }
}
