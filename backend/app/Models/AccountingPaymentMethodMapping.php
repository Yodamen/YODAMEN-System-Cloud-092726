<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPaymentMethodMapping extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','payment_method_reference_id','cash_account_id','customer_facing'];
    protected function casts(): array { return ['customer_facing' => 'boolean']; }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(ReferenceValue::class, 'payment_method_reference_id'); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_account_id'); }
}
