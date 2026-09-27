<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseLine extends Model
{
    use HasFactory;

    protected $fillable = ['expense_id', 'line_no', 'account_id', 'description', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function account(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
}
