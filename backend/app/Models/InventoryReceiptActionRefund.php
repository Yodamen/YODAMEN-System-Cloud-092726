<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceiptActionRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_receipt_action_request_id', 'refund_date', 'receiving_account_id',
        'amount', 'reference', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'refund_date' => 'immutable_date',
            'amount' => 'decimal:2',
        ];
    }

    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptActionRequest::class, 'inventory_receipt_action_request_id');
    }

    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receiving_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
