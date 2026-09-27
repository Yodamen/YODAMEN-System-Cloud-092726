<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'code', 'source_key', 'visit_service_line_id', 'visit_retail_line_id', 'service_id', 'retail_product_id', 'sku_snapshot', 'unit_cost_snapshot', 'selling_uom_snapshot', 'selling_to_basic_factor_snapshot', 'basic_quantity_snapshot',
        'assigned_personnel_id', 'assigned_personnel_name_snapshot', 'item_type', 'item_name_snapshot',
        'service_source', 'source_reference_code', 'package_enrollment_id', 'service_credit_id', 'quantity', 'unit_price', 'line_total', 'discount_rule_id', 'discount_code_snapshot', 'discount_rule_name_snapshot', 'discount_calculation_type_snapshot', 'discount_rate_value_snapshot', 'discount_amount', 'promotion_applications', 'net_line_total', 'applied_amount', 'balance_amount',
        'correction_adjustment_amount', 'refund_adjustment_amount', 'cash_refunded_amount', 'refund_due_amount', 'refunded_quantity', 'returned_quantity', 'effective_net_line_total', 'correction_status', 'refund_status', 'last_transaction_action_request_id',
        'status', 'financial_finalized_at',
        'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:4',
            'selling_to_basic_factor_snapshot' => 'decimal:6',
            'basic_quantity_snapshot' => 'decimal:4',
            'line_total' => 'decimal:2',
            'discount_rate_value_snapshot' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'promotion_applications' => 'array',
            'net_line_total' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'correction_adjustment_amount' => 'decimal:2',
                        'refund_adjustment_amount' => 'decimal:2',
            'cash_refunded_amount' => 'decimal:2',
            'refund_due_amount' => 'decimal:2',
            'refunded_quantity' => 'decimal:4',
            'returned_quantity' => 'decimal:4',
            'effective_net_line_total' => 'decimal:2',
            'financial_finalized_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function visitRetailLine(): BelongsTo { return $this->belongsTo(VisitRetailLine::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function retailProduct(): BelongsTo { return $this->belongsTo(RetailProduct::class, 'retail_product_id'); }
    public function paymentApplications(): HasMany { return $this->hasMany(PaymentApplication::class, 'target_sale_item_id'); }
    public function assignedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'assigned_personnel_id'); }
    public function packageEnrollment(): BelongsTo { return $this->belongsTo(PackageEnrollment::class); }
    public function serviceCredit(): BelongsTo { return $this->belongsTo(ServiceCredit::class); }
    public function discountRule(): BelongsTo { return $this->belongsTo(DiscountRule::class); }
    public function lastTransactionActionRequest(): BelongsTo { return $this->belongsTo(TransactionActionRequest::class, 'last_transaction_action_request_id'); }
}
