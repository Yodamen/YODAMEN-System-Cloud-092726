<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Sale::query()
            ->with(['customer', 'booking', 'visit', 'items', 'payments'])
            ->where('business_id', $user->business_id)
            ->whereIn('status', ['FOR_CHECKOUT', 'PARTIALLY_PAID', 'AWAITING_CONFIRM']);

        if ($user->default_branch_id) {
            $query->where('branch_id', $user->default_branch_id);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn ($q) => $q->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%");
                    });
            });
        }

        $sales = $query->orderByDesc('sale_date')->orderByDesc('id')->get();

        return response()->json([
            'sales' => $sales->map(fn (Sale $sale) => $this->resource($sale))->values(),
        ]);
    }

    public function checkout(Request $request, Sale $sale): JsonResponse
    {
        $this->assertOwned($request, $sale);
        $sale->load(['customer', 'booking', 'visit', 'items.assignedPersonnel', 'payments.paymentMethod']);

        return response()->json([
            'sale' => $this->resource($sale, true),
            'phase' => [
                'checkout_enabled' => false,
                'message' => 'Payment allocation and financial finalization activate in Phase 4. Phase 3 preserves the pending Sale and received Advance Payments.',
            ],
        ]);
    }

    private function resource(Sale $sale, bool $detailed = false): array
    {
        $payments = $sale->payments->where('status', 'RECEIVED')->values();
        $base = [
            'id' => $sale->id,
            'code' => $sale->code,
            'sale_date' => $sale->sale_date?->toIso8601String(),
            'status' => $sale->status,
            'booking' => $sale->booking ? ['id' => $sale->booking->id, 'code' => $sale->booking->code] : null,
            'visit' => $sale->visit ? ['id' => $sale->visit->id, 'code' => $sale->visit->code] : null,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'code' => $sale->customer->code,
                'name' => $sale->customer->full_name,
                'mobile_number' => $sale->customer->mobile_number,
            ] : null,
            'items_count' => $sale->items->count(),
            'services' => $sale->items
                ->filter(fn ($item) => strtoupper((string) $item->item_type) !== 'PRODUCT')
                ->values()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->item_name_snapshot,
                    'service_name' => $item->item_name_snapshot,
                    'status' => $item->status,
                ]),
            'gross_amount' => (float) $sale->gross_amount,
            'advance_payments' => (float) $sale->advance_payments,
            'checkout_payments' => (float) $sale->checkout_payments,
            'total_paid' => (float) $sale->total_paid,
            'outstanding_balance' => (float) $sale->outstanding_balance,
        ];

        if (! $detailed) {
            return $base;
        }

        $base['items'] = $sale->items->map(fn ($item) => [
            'id' => $item->id,
            'code' => $item->code,
            'item_type' => $item->item_type,
            'item_name' => $item->item_name_snapshot,
            'service_source' => $item->service_source,
            'quantity' => $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'line_total' => (float) $item->line_total,
            'status' => $item->status,
            'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
        ])->values();

        $base['payments'] = $payments->map(fn ($payment) => [
            'id' => $payment->id,
            'code' => $payment->code,
            'type' => $payment->payment_type,
            'amount' => (float) $payment->amount,
            'method' => $payment->payment_method_snapshot,
            'reference_no' => $payment->reference_no,
            'payment_date' => $payment->payment_date?->toIso8601String(),
            'status' => $payment->status,
        ])->values();

        return $base;
    }

    private function assertOwned(Request $request, Sale $sale): void
    {
        if ((int) $sale->business_id !== (int) $request->user()->business_id) abort(404);
    }
}
