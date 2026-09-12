<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'code', 'source_key', 'visit_service_line_id', 'visit_retail_line_id', 'service_id',
        'assigned_personnel_id', 'assigned_personnel_name_snapshot', 'item_type', 'item_name_snapshot',
        'service_source', 'source_reference_code', 'quantity', 'unit_price', 'line_total', 'status',
        'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function visitServiceLine(): BelongsTo { return $this->belongsTo(VisitServiceLine::class); }
    public function visitRetailLine(): BelongsTo { return $this->belongsTo(VisitRetailLine::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function assignedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'assigned_personnel_id'); }
}
