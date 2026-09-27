<?php

namespace App\Services\Accounting;

use App\Models\OpeningCustomerReceivable;
use App\Models\PaymentApplication;

class OpeningReceivableService
{
    public function sync(int|OpeningCustomerReceivable $receivable): ?OpeningCustomerReceivable
    {
        $row = $receivable instanceof OpeningCustomerReceivable
            ? $receivable->fresh()
            : OpeningCustomerReceivable::query()->find($receivable);

        if (! $row) return null;

        $applied = round((float) PaymentApplication::query()
            ->where('opening_customer_receivable_id', $row->id)
            ->where('status', 'ACTIVE')
            ->whereHas('payment', fn ($query) => $query->where('status', 'RECEIVED'))
            ->sum('amount'), 2);

        $remaining = max(0, round((float) $row->original_amount - $applied, 2));
        $row->update([
            'remaining_balance' => $remaining,
            'status' => $remaining <= 0.004 ? 'PAID' : 'OPEN',
        ]);

        return $row->fresh();
    }
}
