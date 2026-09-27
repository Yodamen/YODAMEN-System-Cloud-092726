<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'branch_id', 'code', 'expense_date', 'vendor_payee', 'reference',
        'description', 'total_amount', 'status', 'payment_status', 'payment_account_id',
        'payment_method', 'payment_reference', 'payment_splits', 'paid_at', 'paid_by', 'attachment_disk',
        'attachment_path', 'attachment_name', 'attachment_mime', 'notes', 'posted_at',
        'posted_by', 'reversed_at', 'reversed_by', 'reversal_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'immutable_date',
            'total_amount' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'payment_splits' => 'array',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function lines(): HasMany { return $this->hasMany(ExpenseLine::class)->orderBy('line_no'); }
    public function paymentAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'payment_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function reverser(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
    public function reversalRequests(): HasMany { return $this->hasMany(ExpenseReversalRequest::class); }
}
