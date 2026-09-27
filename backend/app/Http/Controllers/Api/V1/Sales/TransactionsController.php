<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\CollectOnAccountRequest;
use App\Http\Requests\Api\V1\Sales\FinalizeCheckoutRequest;
use App\Http\Requests\Api\V1\Sales\PreviewDiscountRequest;
use App\Http\Requests\Api\V1\Sales\ReceiveInterimPaymentRequest;
use App\Models\Customer;
use App\Models\CommissionEarning;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\PackageEnrollment;
use App\Models\ServiceConsumption;
use App\Models\ServiceCreditUsage;
use App\Models\TransactionActionRequest;
use App\Models\TransactionActionRequestLine;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Foundation\IdempotencyService;
use App\Services\Sales\CheckoutSettlementService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Sales\PaymentLedgerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransactionsController extends Controller
{
    public function __construct(
        private readonly CheckoutSettlementService $settlements,
        private readonly IdempotencyService $idempotency,
        private readonly PaymentLedgerService $paymentLedger,
        private readonly CustomerClassificationService $customerClassifications,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Sale::query()
            ->with(['customer', 'booking', 'visit', 'items', 'payments'])
            ->where('business_id', $user->business_id)
            ->whereIn('status', ['FOR_CHECKOUT', 'PARTIALLY_PAID', 'AWAITING_CONFIRM']);

        if ($user->default_branch_id) $query->where('branch_id', $user->default_branch_id);
        $this->applyPendingFilters($query, $request);

        $sales = $query->orderByDesc('sale_date')->orderByDesc('id')->get();
        $this->prewarmCustomerIdentities((int) $user->business_id, $sales->pluck('customer_id')->all());

        return response()->json([
            'sales' => $sales->map(fn (Sale $sale) => $this->saleResource($sale))->values(),
        ]);
    }


    public function reversalOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Sale::query()
            ->with(['booking', 'customer', 'items'])
            ->where('business_id', $user->business_id)
            ->whereNotNull('booking_id')
            ->where('status', '<>', 'REVERSED');

        if ($user->default_branch_id) $query->where('branch_id', $user->default_branch_id);

        $rows = $query->orderByDesc('sale_date')->orderByDesc('id')->get()
            ->map(function (Sale $sale) {
                $activeRequest = TransactionActionRequest::query()
                    ->where('sale_id', $sale->id)
                    ->whereIn('status', ['PENDING_MANAGER_APPROVAL', 'APPROVED_FOR_POSTING'])
                    ->latest('id')->first();

                return [
                    'sale_id' => $sale->id,
                    'sale_code' => $sale->code,
                    'booking_id' => $sale->booking?->id,
                    'booking_code' => $sale->booking?->code,
                    'customer_name' => $sale->customer?->full_name,
                    'status' => $sale->status,
                    'financial_finalized' => $sale->financial_finalized_at !== null,
                    'amount' => (float) ($sale->effective_net_amount ?? $sale->net_amount ?? 0),
                    'total_paid' => (float) ($sale->total_paid ?? 0),
                    'outstanding_balance' => (float) ($sale->outstanding_balance ?? 0),
                    'active_request' => $activeRequest ? [
                        'id' => $activeRequest->id,
                        'code' => $activeRequest->code,
                        'action_type' => $activeRequest->action_type,
                        'status' => $activeRequest->status,
                    ] : null,
                ];
            })->values();

        return response()->json(['bookings' => $rows]);
    }

    public function reversalPreview(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();
        if ((int) $sale->business_id !== (int) $user->business_id) abort(404);
        if ($user->default_branch_id && (int) $sale->branch_id !== (int) $user->default_branch_id) abort(404);
        if (! $sale->booking_id) return response()->json(['message' => 'Select a Booking-linked transaction for reversal.'], 422);
        if (strtoupper((string) $sale->status) === 'REVERSED') return response()->json(['message' => 'This Booking transaction is already reversed.'], 422);

        $sale->load(['booking', 'customer', 'items.retailProduct', 'items.service', 'items.packageEnrollment', 'visit']);

        $activeRequest = TransactionActionRequest::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['PENDING_MANAGER_APPROVAL', 'APPROVED_FOR_POSTING'])
            ->latest('id')->first();

        $activeApplied = round((float) PaymentApplication::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'ACTIVE')
            ->sum('amount'), 2);

        $productLines = $sale->items->filter(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'PRODUCT');
        $serviceLines = $sale->items->filter(fn (SaleItem $item) => in_array(strtoupper((string) $item->item_type), ['SERVICE', 'PACKAGE', 'SERVICE_CREDIT_USE'], true));

        $retailRestoreBasicQty = round((float) (InventoryMovement::query()
            ->where('sale_id', $sale->id)
            ->where('movement_type', 'RETAIL_SALE')
            ->selectRaw('COALESCE(SUM(ABS(quantity_change)), 0) as total')
            ->value('total') ?? 0), 4);

        $serviceConsumptionIds = ServiceConsumption::query()
            ->where('visit_id', $sale->visit_id)
            ->pluck('id');
        $serviceConsumptionCost = $serviceConsumptionIds->isEmpty()
            ? 0.0
            : round((float) ServiceConsumption::query()->whereIn('id', $serviceConsumptionIds)->sum('total_cost'), 2);

        $commissionAmount = round((float) CommissionEarning::query()
            ->where('sale_id', $sale->id)
            ->where('status', '<>', 'REVERSED')
            ->sum('commission_amount'), 2);
        $commissionAmount = max(0, $commissionAmount);
        $serviceCreditUsageCount = $sale->visit_id ? ServiceCreditUsage::query()
            ->where('visit_id', $sale->visit_id)
            ->whereNull('reversed_at')
            ->count() : 0;
        $packageCount = $sale->items->filter(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'PACKAGE')->count();

        return response()->json([
            'booking' => [
                'id' => $sale->booking?->id,
                'code' => $sale->booking?->code,
                'customer_name' => $sale->customer?->full_name,
                'sale_id' => $sale->id,
                'sale_code' => $sale->code,
                'status' => $sale->status,
                'financial_finalized' => $sale->financial_finalized_at !== null,
            ],
            'active_request' => $activeRequest ? [
                'id' => $activeRequest->id,
                'code' => $activeRequest->code,
                'action_type' => $activeRequest->action_type,
                'status' => $activeRequest->status,
            ] : null,
            'summary' => [
                'operational' => [
                    ['label' => 'Service lines removed from effective visit', 'value' => $serviceLines->count()],
                    ['label' => 'Retail product lines reversed', 'value' => $productLines->count()],
                    ['label' => 'Retail inventory restored (Basic UOM)', 'value' => $retailRestoreBasicQty],
                    ['label' => 'Service consumption effective cost reversed', 'value' => $serviceConsumptionCost],
                    ['label' => 'Service credit usages reversed', 'value' => $serviceCreditUsageCount],
                    ['label' => 'Origin package records reviewed/cancelled when safe', 'value' => $packageCount],
                    ['label' => 'Commission amount reversed', 'value' => $commissionAmount],
                    ['label' => 'Booking destination', 'value' => 'Operations > Bookings > Others'],
                    ['label' => 'Transaction destination', 'value' => 'Sales > Transactions > Reversed'],
                ],
                'financial' => [
                    ['label' => 'Effective transaction amount becomes', 'value' => 0],
                    ['label' => 'Current applied/collected amount becoming Refund Due', 'value' => $activeApplied],
                    ['label' => 'Current Balance Due cleared', 'value' => (float) ($sale->outstanding_balance ?? 0)],
                    ['label' => 'Received payment history', 'value' => 'Preserved; applications are counter-posted'],
                    ['label' => 'Closed Z-reading/history', 'value' => 'Not rewritten; reversal posts on action date'],
                ],
            ],
        ]);
    }

    public function checkout(Request $request, Sale $sale): JsonResponse
    {
        try {
            $data = $this->settlements->checkoutData($request->user(), $sale);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'sale' => $this->saleResource($data['sale'], true),
            'allocation' => [
                'items' => $data['items'],
                'advance_payments' => $data['advance_payments'],
                'advance_available' => $data['advance_available'],
                'advance_required_allocation' => $data['advance_required_allocation'],
                'booking_interim_confirmation_required' => $data['booking_interim_confirmation_required'],
                'current_chargeable_balance' => $data['current_chargeable_balance'],
                'discount' => $data['discount'],
                'payment_methods' => $data['payment_methods'],
                'tip_personnel' => $data['tip_personnel'] ?? [],
            ],
            'phase' => ['checkout_enabled' => true],
        ]);
    }

    public function discountPreview(PreviewDiscountRequest $request, Sale $sale): JsonResponse
    {
        try {
            $data = $this->settlements->checkoutData(
                $request->user(),
                $sale,
                $request->validated('discount_codes') ?? $request->validated('discount_code'),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'discount' => $data['discount'],
            'allocation' => [
                'items' => $data['items'],
                'advance_payments' => $data['advance_payments'],
                'advance_available' => $data['advance_available'],
                'advance_required_allocation' => $data['advance_required_allocation'],
                'booking_interim_confirmation_required' => $data['booking_interim_confirmation_required'],
                'current_chargeable_balance' => $data['current_chargeable_balance'],
                'discount' => $data['discount'],
                'payment_methods' => $data['payment_methods'],
                'tip_personnel' => $data['tip_personnel'] ?? [],
            ],
        ]);
    }

    public function finalize(FinalizeCheckoutRequest $request, Sale $sale, SupportingDocumentService $documents): JsonResponse
    {
        $payload = $request->validated();

        try {
            $paymentSplits = collect($payload['payment_splits'] ?? [])
                ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0.009);
            $paymentMethodIds = $paymentSplits
                ->pluck('payment_method_reference_id');
            if ($paymentSplits->isEmpty() && (float) ($payload['amount_collected_now'] ?? 0) > 0.009) {
                $paymentMethodIds->push($payload['payment_method_reference_id'] ?? null);
            }
            if ((float) ($payload['tip_amount'] ?? 0) > 0.009) {
                $paymentMethodIds->push($payload['tip_payment_method_reference_id'] ?? null);
            }
            $paymentMethodIds = $paymentMethodIds
                ->filter()
                ->values()
                ->all();
            $documents->assertPaymentProofForMethods(
                $request->user(),
                $paymentMethodIds,
                $payload['attachment_tokens'] ?? [],
                'Checkout payment',
            );

            $result = $this->idempotency->execute(
                scope: 'sales.finalize-checkout.'.$sale->id,
                key: $payload['idempotency_key'],
                payload: $payload,
                callback: function () use ($request, $sale, $payload, $documents) {
                    $finalized = $this->settlements->finalize($request->user(), $sale, $payload);
                    $documents->claimTokens($request->user(), $payload['attachment_tokens'] ?? [], 'sales', 'SALE_CHECKOUT', (int) $sale->id, 'CHECKOUT_PAYMENT_PROOF');
                    return ['status' => 200, 'body' => ['sale' => $this->saleResource($finalized, true)]];
                },
                user: $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function settlementLines(Request $request): JsonResponse
    {
        $classification = strtoupper(trim((string) $request->query('classification', 'PAID')));
        if (! in_array($classification, ['PAID', 'ON_ACCOUNT', 'SERVICE_CREDIT', 'REVERSED'], true)) {
            return response()->json(['message' => 'Unsupported settlement classification.'], 422);
        }

        $user = $request->user();

        $query = SaleItem::query()
            ->with([
                'sale.booking',
                'sale.customer',
                'packageEnrollment.booking',
                'packageEnrollment.originSale',
            'packageEnrollment.originSaleItem.sale.booking',
            ])
            ->whereHas('sale', function ($saleQuery) use ($user) {
                $saleQuery->where('business_id', $user->business_id)
                    ->whereNotNull('financial_finalized_at');

                if ($user->default_branch_id) {
                    $saleQuery->where('branch_id', $user->default_branch_id);
                }
            });

        if ($classification === 'REVERSED') {
            $query->where(function ($reversedQuery) {
                $reversedQuery->whereIn('correction_status', ['REMOVED', 'REVERSED'])
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'));
            });
        } else {
            // Removed/reversed lines are historical exceptions. Keep them out of
            // the active Paid / On Account / Service Credit views and surface them
            // exclusively in the Reversed tab for a single audit destination.
            $query->whereNotIn('correction_status', ['REMOVED', 'REVERSED'])
                ->whereHas('sale', fn ($saleQuery) => $saleQuery->where('status', '<>', 'REVERSED'));
        }

        $this->applySettlementFilters($query, $request);

        $items = $query
            ->orderByDesc('financial_finalized_at')
            ->orderByDesc('id')
            ->get();

        $this->prewarmCustomerIdentities(
            (int) $user->business_id,
            $items->map(fn (SaleItem $item) => $item->sale?->customer_id)->all(),
        );

        $lines = $items
            ->filter(fn (SaleItem $item) => $this->settlementItemClassification($item) === $classification)
            ->map(fn (SaleItem $item) => $this->settlementItemResource($item))
            ->values();

        return response()->json(['lines' => $lines]);
    }


    public function collection(Request $request, Sale $sale): JsonResponse
    {
        try {
            $data = $this->settlements->collectionData($request->user(), $sale);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'sale' => $this->saleResource($data['sale'], true),
            'items' => $data['items'],
            'payment_methods' => $data['payment_methods'],
        ]);
    }

    public function collect(CollectOnAccountRequest $request, Sale $sale): JsonResponse
    {
        $payload = $request->validated();

        try {
            $result = $this->idempotency->execute(
                scope: 'sales.collection.'.$sale->id,
                key: $payload['idempotency_key'],
                payload: $payload,
                callback: function () use ($request, $sale, $payload) {
                    $collected = $this->settlements->collect($request->user(), $sale, $payload);
                    return ['status' => 200, 'body' => ['sale' => $this->saleResource($collected, true)]];
                },
                user: $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function onAccountCustomers(Request $request): JsonResponse
    {
        return response()->json([
            'customers' => $this->settlements->onAccountCustomers(
                $request->user(),
                (string) $request->query('search', ''),
            ),
        ]);
    }

    public function customerOnAccount(Request $request, Customer $customer): JsonResponse
    {
        return response()->json($this->settlements->customerOnAccountData($request->user(), $customer));
    }

    public function receiveInterimPayment(ReceiveInterimPaymentRequest $request, Customer $customer): JsonResponse
    {
        $payload = $request->validated();

        try {
            $result = $this->idempotency->execute(
                scope: 'sales.interim-payment.customer.'.$customer->id,
                key: $payload['idempotency_key'],
                payload: $payload,
                callback: function () use ($request, $customer, $payload) {
                    $payment = $this->settlements->receiveInterimPayment($request->user(), $customer, $payload);
                    return [
                        'status' => 200,
                        'body' => [
                            'payment' => [
                                'id' => $payment->id,
                                'code' => $payment->code,
                                'amount' => (float) $payment->amount,
                                'type' => 'INTERIM_PAYMENT',
                            ],
                        ],
                    ];
                },
                user: $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result['body'], $result['status']);
    }

    private function saleResource(Sale $sale, bool $detailed = false): array
    {
        $sale->loadMissing(['customer', 'booking', 'visit', 'items.assignedPersonnel', 'items.packageEnrollment.originSale', 'payments.booking']);
        $effectiveItems = $sale->items
            ->reject(fn ($item) => in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
            ->values();
        $payments = $this->receivedPaymentsForSale($sale);
        $bookingPaymentTotals = $sale->booking
            ? $this->paymentLedger->bookingAllocationTotals($sale->booking)
            : ['advance' => 0.0, 'interim' => 0.0, 'checkout' => 0.0];
        $paymentsReceived = round((float) $sale->total_paid, 2);
        $payableAmount = (float) (($sale->effective_net_amount ?? $sale->net_amount ?? 0) > 0 || (float) $sale->gross_amount <= 0 ? ($sale->effective_net_amount ?? $sale->net_amount) : $sale->gross_amount);
        $pendingBalance = max(0, round($payableAmount - min($payableAmount, $paymentsReceived), 2));

        $customerIdentity = $sale->customer
            ? $this->customerClassifications->identityForCustomer($sale->customer)
            : null;

        $base = [
            'id' => $sale->id,
            'code' => $sale->code,
            'sale_date' => $sale->sale_date?->toIso8601String(),
            'status' => $sale->status,
            'transaction_classification' => $sale->financial_finalized_at ? $this->settlements->transactionClassification($sale) : null,
            'financial_finalized_at' => $sale->financial_finalized_at?->toIso8601String(),
            'booking' => $sale->booking ? ['id' => $sale->booking->id, 'code' => $sale->booking->code] : null,
            'visit' => $sale->visit ? ['id' => $sale->visit->id, 'code' => $sale->visit->code] : null,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'code' => $sale->customer->code,
                'name' => $sale->customer->full_name,
                'mobile_number' => $sale->customer->mobile_number,
                'segment' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type_color' => $customerIdentity['customer_type_color'] ?? '#343434',
                'customer_class' => $customerIdentity['customer_class'] ?? 'Unclassified',
            ] : null,
            'items_count' => $effectiveItems->count(),
            'services' => $effectiveItems
                ->filter(fn ($item) => strtoupper((string) $item->item_type) !== 'PRODUCT')
                ->values()->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->item_name_snapshot,
                    'service_name' => $item->item_name_snapshot,
                    'status' => $item->status,
                    'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
                    'is_multi_session' => $item->packageEnrollment !== null,
                    'package_code' => $item->packageEnrollment?->code,
                ]),
            'gross_amount' => (float) $sale->gross_amount,
            'discount_amount' => (float) ($sale->discount_amount ?? 0),
            'net_amount' => (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount),
            'effective_net_amount' => (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount),
            'refund_due_amount' => (float) ($sale->refund_due_amount ?? 0),
            'refund_status' => $sale->refund_status ?? 'NONE',
            'discount_code' => $sale->discount_code_snapshot,
            'promotion_codes' => $sale->promotion_codes_snapshot ?: ($sale->discount_code_snapshot ? [$sale->discount_code_snapshot] : []),
            'advance_payments' => (float) $sale->advance_payments,
            'checkout_payments' => (float) $sale->checkout_payments,
            'payments_received' => $paymentsReceived,
            'booking_advance_payment_total' => (float) $bookingPaymentTotals['advance'],
            'booking_interim_payment_total' => (float) $bookingPaymentTotals['interim'],
            'booking_checkout_payment_total' => (float) ($bookingPaymentTotals['checkout'] ?? 0),
            'booking_collection_total' => round((float) $bookingPaymentTotals['advance'] + (float) $bookingPaymentTotals['interim'] + (float) ($bookingPaymentTotals['checkout'] ?? 0), 2),
            'total_paid' => (float) $sale->total_paid,
            'applied_amount' => (float) ($sale->applied_amount ?? 0),
            'outstanding_balance' => $sale->financial_finalized_at ? (float) $sale->outstanding_balance : $pendingBalance,
        ];

        if (! $detailed) return $base;

        $base['items'] = $sale->items->map(function ($item) {
            $history = $this->correctionHistoryForItem($item);
            $original = $history['original_snapshot'] ?? [];

            return [
                'id' => $item->id,
                'code' => $item->code,
                'item_type' => $item->item_type,
                'item_name' => $item->item_name_snapshot,
                'service_source' => $item->service_source,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'net_line_total' => (float) ($item->net_line_total ?? $item->line_total),
                'effective_net_line_total' => (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total),
                'original_quantity' => (int) ($original['quantity'] ?? $item->quantity),
                'original_unit_price' => (float) ($original['unit_price'] ?? $item->unit_price),
                'original_net_line_total' => (float) ($original['effective_net_line_total'] ?? $original['net_line_total'] ?? $original['line_total'] ?? $item->net_line_total ?? $item->line_total),
                'refund_due_amount' => (float) ($item->refund_due_amount ?? 0),
                'correction_status' => $item->correction_status ?? 'ACTIVE',
                'correction_history' => $history ? [
                    'request_code' => $history['request_code'],
                    'reason' => $history['reason'],
                    'remarks' => $history['remarks'],
                    'posted_at' => $history['posted_at'],
                    'line_action' => $history['line_action'],
                ] : null,
                'refund_status' => $item->refund_status ?? 'NONE',
                'discount_code' => $item->discount_code_snapshot,
                'discount_rule_name' => $item->discount_rule_name_snapshot,
                'promotion_applications' => $item->promotion_applications ?: [],
                'applied_amount' => (float) ($item->applied_amount ?? 0),
                'balance_amount' => (float) ($item->balance_amount ?? $item->net_line_total ?? $item->line_total),
                'status' => $item->status,
                'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
            ];
        })->values();

        $base['payments'] = $payments->map(fn ($payment) => [
            'id' => $payment->id,
            'code' => $payment->code,
            'type' => $payment->payment_type,
            'amount' => (float) $payment->amount,
            'method' => $payment->payment_method_snapshot,
            'reference_no' => $payment->reference_no,
            'payment_date' => $payment->payment_date?->toIso8601String(),
            'status' => $payment->status,
            'booking' => $payment->booking ? [
                'id' => $payment->booking->id,
                'code' => $payment->booking->code,
            ] : null,
        ])->values();

        return $base;
    }

    private function receivedPaymentsForSale(Sale $sale): Collection
    {
        $direct = $sale->payments
            ->where('status', 'RECEIVED')
            ->reject(fn ($payment) => strtoupper((string) $payment->payment_type) === 'TIP')
            ->values();

        $applicationPayments = PaymentApplication::query()
            ->with('payment.booking')
            ->where('sale_id', $sale->id)
            ->where('status', 'ACTIVE')
            ->whereHas('payment', fn ($query) => $query->where('status', 'RECEIVED')->where('payment_type', '!=', 'TIP'))
            ->get()
            ->pluck('payment')
            ->filter();

        return $direct
            ->concat($applicationPayments)
            ->unique('id')
            ->sortBy([['payment_date', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function settlementItemResource(SaleItem $item): array
    {
        $item->loadMissing([
            'sale.booking',
            'sale.customer',
            'sale.reversalActionRequest.reviewer',
            'sale.reversalActionRequest.poster',
            'sale.reversedBy',
            'packageEnrollment.booking',
            'packageEnrollment.originSale',
            'packageEnrollment.originSaleItem.sale.booking',
        ]);

        $sale = $item->sale;
        $classification = $this->settlementItemClassification($item);
        $correctionHistory = $this->correctionHistoryForItem($item);
        $reversalRequest = $sale?->reversalActionRequest;
        $reversalApprover = $reversalRequest?->reviewer ?: $reversalRequest?->poster ?: $sale?->reversedBy;
        $approvedBy = $correctionHistory['approved_by'] ?? ($reversalApprover ? ($reversalApprover->display_name ?: $reversalApprover->name) : null);
        $originalSnapshot = $correctionHistory['original_snapshot'] ?? [];
        $isRemovedByCorrection = strtoupper((string) ($item->correction_status ?? 'ACTIVE')) === 'REMOVED';
        $isServiceCredit = strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE';
        $originItem = $isServiceCredit ? $item->packageEnrollment?->originSaleItem : null;
        $paymentTargetItemId = $originItem?->id ?: $item->id;
        $isPackageLine = $item->packageEnrollment !== null;
        $packageCode = $item->packageEnrollment?->code;

        $bookingApplications = $this->bookingApplicationsForItem($sale, $paymentTargetItemId);
        $paymentAmount = round((float) $bookingApplications->sum('amount'), 2);
        $paymentDetails = $bookingApplications
            ->map(fn (PaymentApplication $application) => $this->paymentApplicationResource($application))
            ->values()
            ->all();

        $displayBalance = match (true) {
            $isPackageLine && $classification === 'PAID' => 0.0,
            $isPackageLine && $classification === 'ON_ACCOUNT' => $this->packageBalanceAfterSale($item->packageEnrollment, $sale),
            default => (float) $item->balance_amount,
        };

        $packageHistories = [];
        if ($isServiceCredit && $item->packageEnrollment) {
            $history = $this->packageHistoryResource($item->packageEnrollment, $sale);
            if (count($history['payments']) > 0) {
                $packageHistories[] = $history;
            }
        }

        $customerIdentity = $sale->customer
            ? $this->customerClassifications->identityForCustomer($sale->customer)
            : null;

        return [
            'id' => 'item:'.$item->id,
            'sale_item_id' => $item->id,
            'sale_id' => $sale->id,
            'sale_code' => $sale->code,
            'booking' => $sale->booking ? [
                'id' => $sale->booking->id,
                'code' => $sale->booking->code,
            ] : null,
            'transaction_date' => $sale->sale_date?->toIso8601String(),
            'financial_finalized_at' => $item->financial_finalized_at?->toIso8601String(),
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->full_name,
                'mobile_number' => $sale->customer->mobile_number,
                'segment' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type_color' => $customerIdentity['customer_type_color'] ?? '#343434',
                'customer_class' => $customerIdentity['customer_class'] ?? 'Unclassified',
            ] : null,
            'classification' => $classification,
            'parent_status' => strtoupper((string) $sale->status),
            'item_names' => [$item->item_name_snapshot],
            'item_type' => $item->item_type,
            'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
            'is_multi_session' => $isPackageLine,
            'package_code' => $packageCode,
            'gross_amount' => (float) $item->line_total,
            'discount_amount' => (float) ($item->discount_amount ?? 0),
            'original_quantity' => (int) ($originalSnapshot['quantity'] ?? $item->quantity),
            'original_unit_price' => (float) ($originalSnapshot['unit_price'] ?? $item->unit_price),
            'original_amount' => (float) ($originalSnapshot['effective_net_line_total'] ?? $originalSnapshot['net_line_total'] ?? $originalSnapshot['line_total'] ?? $item->net_line_total ?? $item->line_total),
            'amount' => (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total),
            'cash_refunded_amount' => (float) ($item->cash_refunded_amount ?? 0),
            'refund_due_amount' => (float) ($item->refund_due_amount ?? 0),
            'correction_status' => $item->correction_status ?? 'ACTIVE',
            'historical_status' => $isRemovedByCorrection ? 'REMOVED_BY_CORRECTION' : null,
            'approved_by' => $approvedBy,
            'correction_history' => $correctionHistory ? [
                'request_code' => $correctionHistory['request_code'],
                'reason' => $correctionHistory['reason'],
                'remarks' => $correctionHistory['remarks'],
                'posted_at' => $correctionHistory['posted_at'],
                'line_action' => $correctionHistory['line_action'],
            ] : null,
            'refund_status' => $item->refund_status ?? 'NONE',
            'discount_code' => $item->discount_code_snapshot,
            'payment_amount' => $paymentAmount,
            'payment_type_summary' => $this->bookingPaymentTypeSummary($bookingApplications, $displayBalance),
            'balance_amount' => $displayBalance,
            'payment_details' => $paymentDetails,
            'package_histories' => $packageHistories,
        ];
    }

    private function packageBalanceAfterSale(?PackageEnrollment $enrollment, Sale $sale): float
    {
        if (! $enrollment) return 0.0;

        $enrollment->loadMissing('originSaleItem');
        $originItem = $enrollment->originSaleItem;
        if (! $originItem) return 0.0;

        $cutoff = $sale->financial_finalized_at ?: $sale->created_at;

        $applications = PaymentApplication::query()
            ->where('target_sale_item_id', $originItem->id)
            ->where('status', 'ACTIVE')
            ->whereHas('payment', fn ($query) => $query->where('status', 'RECEIVED'));

        if ($cutoff) {
            $applications->where('created_at', '<=', $cutoff);
        }

        $paidThroughThisBooking = round((float) $applications->sum('amount'), 2);

        $packagePayable = (float) ($originItem->effective_net_line_total ?? $originItem->net_line_total ?? $originItem->line_total);
        return max(0, round($packagePayable - $paidThroughThisBooking, 2));
    }

    private function correctionHistoryForItem(SaleItem $item): ?array
    {
        $line = TransactionActionRequestLine::query()
            ->with(['request.reviewer', 'request.poster'])
            ->where('sale_item_id', $item->id)
            ->whereHas('request', function ($query) {
                $query->where('action_type', 'CORRECTION')
                    ->where('status', 'POSTED');
            })
            ->orderByDesc('id')
            ->first();

        if (! $line || ! $line->request) return null;

        return [
            'request_code' => $line->request->code,
            'reason' => $line->request->reason_label,
            'remarks' => $line->request->remarks,
            'posted_at' => $line->request->posted_at?->toIso8601String(),
            'line_action' => strtoupper((string) $line->line_action),
            'approved_by' => $line->request->reviewer
                ? ($line->request->reviewer->display_name ?: $line->request->reviewer->name)
                : ($line->request->poster ? ($line->request->poster->display_name ?: $line->request->poster->name) : null),
            'original_snapshot' => $line->original_snapshot ?: [],
            'proposed_snapshot' => $line->proposed_snapshot ?: [],
        ];
    }

    private function settlementItemClassification(SaleItem $item): string
    {
        $item->loadMissing(['sale.booking', 'packageEnrollment.originSaleItem']);
        $sale = $item->sale;
        $itemType = strtoupper((string) $item->item_type);

        if (strtoupper((string) $sale?->status) === 'REVERSED') {
            return 'REVERSED';
        }

        if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) {
            return 'REVERSED';
        }

        if ($itemType !== 'SERVICE_CREDIT_USE') {
            $status = strtoupper((string) $item->status);
            if (in_array($status, ['PAID', 'ON_ACCOUNT', 'SERVICE_CREDIT'], true)) {
                return $status;
            }

            return (float) $item->balance_amount <= 0.009 ? 'PAID' : 'ON_ACCOUNT';
        }

        $originItem = $item->packageEnrollment?->originSaleItem;
        if (! $originItem || ! $sale?->booking_id) {
            return 'SERVICE_CREDIT';
        }

        $paymentIds = Payment::query()
            ->where('booking_id', $sale->booking_id)
            ->where('status', 'RECEIVED')
            ->pluck('id');

        if ($paymentIds->isEmpty()) {
            return 'SERVICE_CREDIT';
        }

        $hasPackagePayment = PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE')
            ->where('target_type', 'ON_ACCOUNT_LINE')
            ->where('target_sale_item_id', $originItem->id)
            ->exists();

        if (! $hasPackagePayment) {
            return 'SERVICE_CREDIT';
        }

        return (float) $originItem->balance_amount > 0.009 ? 'ON_ACCOUNT' : 'PAID';
    }

    private function bookingApplicationsForItem(Sale $sale, int $targetSaleItemId): Collection
    {
        if ($sale->booking_id) {
            $paymentIds = Payment::query()
                ->where('booking_id', $sale->booking_id)
                ->where('status', 'RECEIVED')
                ->pluck('id');
        } else {
            // Retail POS sales do not have a Booking. Their received payments are
            // linked directly to the Sale, so resolve applications from sale_id.
            $paymentIds = Payment::query()
                ->where('sale_id', $sale->id)
                ->where('status', 'RECEIVED')
                ->pluck('id');
        }

        if ($paymentIds->isEmpty()) return collect();

        return PaymentApplication::query()
            ->with(['payment.booking', 'targetSaleItem'])
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE')
            ->where('target_sale_item_id', $targetSaleItemId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function bookingApplicationsForSettlementRow(
        Sale $sale,
        string $classification,
        Collection $currentPackageItemIds,
        Collection $packageOriginItemIds,
    ): Collection {
        if (! $sale->booking_id) return collect();

        $paymentIds = Payment::query()
            ->where('booking_id', $sale->booking_id)
            ->where('status', 'RECEIVED')
            ->pluck('id');

        if ($paymentIds->isEmpty()) return collect();

        $query = PaymentApplication::query()
            ->with(['payment.booking', 'targetSaleItem'])
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE');

        $hasServiceCredit = $sale->items
            ->contains(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE');

        if ($hasServiceCredit && in_array($classification, ['ON_ACCOUNT', 'PAID'], true)) {
            // A Booking with a Service Credit claim can collect money during Booking
            // or Checkout and apply it to the package that funded that claim. Keep
            // the transaction row strictly tied to this Booking's receipts, while
            // showing only applications to the claimed package origin line.
            if ($packageOriginItemIds->isEmpty()) return collect();
            $query->where('target_type', 'ON_ACCOUNT_LINE')
                ->whereIn('target_sale_item_id', $packageOriginItemIds);
        } elseif ($classification === 'ON_ACCOUNT') {
            // For a package-purchase Booking, show only payments made during this
            // same Booking and actually applied to that Booking's package line.
            if ($currentPackageItemIds->isEmpty()) return collect();
            $query->whereIn('target_sale_item_id', $currentPackageItemIds);
        } else {
            // Paid rows show only applications to this Booking's own Sale. Payments
            // collected under the Booking but applied to an older package are not
            // added to the current row's Payment amount.
            $query->where('sale_id', $sale->id)
                ->whereIn('target_type', ['CURRENT_SALE_LINE', 'ADVANCE_LINE', 'CHECKOUT_LINE', 'ON_ACCOUNT_LINE']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function packageEnrollmentsForSale(Sale $sale): Collection
    {
        $packageIds = $sale->items
            ->pluck('package_enrollment_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        $packageItemIds = $sale->items
            ->filter(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'PACKAGE')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $paymentIds = $sale->booking_id
            ? Payment::query()
                ->where('booking_id', $sale->booking_id)
                ->where('status', 'RECEIVED')
                ->pluck('id')
            : collect();

        $interimTargetItemIds = $paymentIds->isEmpty()
            ? collect()
            : PaymentApplication::query()
                ->whereIn('payment_id', $paymentIds)
                ->where('target_type', 'ON_ACCOUNT_LINE')
                ->where('status', 'ACTIVE')
                ->whereNotNull('target_sale_item_id')
                ->pluck('target_sale_item_id')
                ->map(fn ($id) => (int) $id);

        $originItemIds = $packageItemIds
            ->concat($interimTargetItemIds)
            ->unique()
            ->values();

        if ($packageIds->isEmpty() && $originItemIds->isEmpty()) return collect();

        $query = PackageEnrollment::query()
            ->with(['booking', 'originSaleItem.sale.booking'])
            ->where(function ($builder) use ($packageIds, $originItemIds) {
                if ($packageIds->isNotEmpty()) {
                    $builder->whereIn('id', $packageIds);
                }
                if ($originItemIds->isNotEmpty()) {
                    if ($packageIds->isNotEmpty()) $builder->orWhereIn('origin_sale_item_id', $originItemIds);
                    else $builder->whereIn('origin_sale_item_id', $originItemIds);
                }
            });

        return $query->get()->unique('id')->values();
    }


    private function packageHistoryResource(PackageEnrollment $enrollment, Sale $currentSale): array
    {
        $originItem = $enrollment->originSaleItem;
        $currentBooking = $currentSale->booking;
        $cutoff = $currentBooking?->created_at ?: $currentSale->created_at;

        if (! $originItem) {
            return [
                'package_enrollment_id' => $enrollment->id,
                'package_code' => $enrollment->code,
                'package_name' => $enrollment->service_name_snapshot,
                'origin_booking' => $enrollment->booking ? [
                    'id' => $enrollment->booking->id,
                    'code' => $enrollment->booking->code,
                ] : null,
                'current_balance' => 0.0,
                'payments' => [],
            ];
        }

        $applications = PaymentApplication::query()
            ->with('payment.booking')
            ->where('target_sale_item_id', $originItem->id)
            ->where('status', 'ACTIVE')
            ->whereHas('payment', function ($query) use ($currentSale, $cutoff) {
                $query->where('status', 'RECEIVED');
                if ($currentSale->booking_id) {
                    $query->where(function ($bookingQuery) use ($currentSale) {
                        $bookingQuery->whereNull('booking_id')
                            ->orWhere('booking_id', '<>', $currentSale->booking_id);
                    });
                }
                if ($cutoff) {
                    $query->where('payment_date', '<', $cutoff);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            'package_enrollment_id' => $enrollment->id,
            'package_code' => $enrollment->code,
            'package_name' => $enrollment->service_name_snapshot,
            'origin_booking' => $enrollment->booking ? [
                'id' => $enrollment->booking->id,
                'code' => $enrollment->booking->code,
            ] : null,
            'current_balance' => (float) ($originItem->balance_amount ?? 0),
            'payments' => $applications
                ->map(fn (PaymentApplication $application) => $this->paymentApplicationResource($application))
                ->values()
                ->all(),
        ];
    }

    private function paymentApplicationResource(PaymentApplication $application): array
    {
        $payment = $application->payment;
        $targetType = strtoupper((string) $application->target_type);
        $paymentType = strtoupper((string) ($payment?->payment_type ?? ''));

        $source = strtoupper((string) data_get($application->target_snapshot, 'source', ''));
        $applicationType = match (true) {
            $source === 'SERVICE_CREDIT_INTERIM_ADVANCE' => 'ADVANCE',
            $source === 'SERVICE_CREDIT_INTERIM_CHECKOUT' => 'CHECKOUT',
            in_array($targetType, ['ADVANCE_LINE', 'ADVANCE_RESERVE'], true) => 'ADVANCE',
            $targetType === 'CHECKOUT_LINE' => 'CHECKOUT',
            $targetType === 'ON_ACCOUNT_LINE' => 'INTERIM',
            default => $paymentType,
        };

        return [
            'payment_id' => $payment?->id,
            'payment_code' => $payment?->code,
            'payment_type' => $payment?->payment_type,
            'application_type' => $applicationType,
            'amount' => round((float) $application->amount, 2),
            'receipt_amount' => (float) ($payment?->amount ?? 0),
            'payment_method' => $payment?->payment_method_snapshot,
            'reference_no' => $payment?->reference_no,
            'payment_date' => $payment?->payment_date?->toIso8601String(),
            'booking' => $payment?->booking ? [
                'id' => $payment->booking->id,
                'code' => $payment->booking->code,
            ] : null,
        ];
    }

    private function bookingPaymentTypeSummary(Collection $applications, float $balance): string
    {
        if ($applications->isEmpty()) return '';
        if ($balance <= 0.009) return 'Final';

        $types = $applications
            ->map(function (PaymentApplication $application) {
                $targetType = strtoupper((string) $application->target_type);
                $source = strtoupper((string) data_get($application->target_snapshot, 'source', ''));
                if ($source === 'SERVICE_CREDIT_INTERIM_ADVANCE') return 'Advance';
                if ($source === 'SERVICE_CREDIT_INTERIM_CHECKOUT') return 'Checkout';

                return match ($targetType) {
                    'ADVANCE_LINE', 'ADVANCE_RESERVE' => 'Advance',
                    'ON_ACCOUNT_LINE' => 'Interim',
                    'CHECKOUT_LINE' => 'Checkout',
                    default => match (strtoupper((string) ($application->payment?->payment_type ?? ''))) {
                        'ADVANCE' => 'Advance',
                        'INTERIM' => 'Interim',
                        'CHECKOUT' => 'Checkout',
                        default => '',
                    },
                };
            })
            ->filter()
            ->unique()
            ->values();

        if ($types->contains('Advance') && $types->contains('Checkout')) return 'Advance + Checkout';
        if ($types->contains('Interim') && $types->contains('Checkout')) return 'Interim + Checkout';
        if ($types->contains('Checkout')) return 'Checkout';
        if ($types->contains('Advance')) return 'Advance';
        if ($types->contains('Interim')) return 'Interim';

        return '';
    }

    private function applyPendingFilters($query, Request $request): void
    {
        $this->applySearch($query, trim((string) $request->query('search', '')));

        if ($start = trim((string) $request->query('start_date', ''))) {
            $query->whereDate('sale_date', '>=', $start);
        }
        if ($end = trim((string) $request->query('end_date', ''))) {
            $query->whereDate('sale_date', '<=', $end);
        }

        if ($bookingId = trim((string) $request->query('booking_id', ''))) {
            $query->whereHas('booking', fn ($bookingQuery) => $bookingQuery->where('code', 'like', "%{$bookingId}%"));
        }

        if ($packageId = trim((string) $request->query('package_id', ''))) {
            $query->whereHas('items.packageEnrollment', fn ($packageQuery) => $packageQuery->where('code', 'like', "%{$packageId}%"));
        }

        if ($customerName = trim((string) $request->query('customer_name', ''))) {
            $query->whereHas('customer', function ($customerQuery) use ($customerName) {
                $this->applyPersonNameSearch($customerQuery, $customerName);
            });
        }

        if ($serviceName = trim((string) $request->query('service_name', ''))) {
            $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('item_name_snapshot', 'like', "%{$serviceName}%"));
        }

        if ($staffName = trim((string) $request->query('staff_name', ''))) {
            $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('assigned_personnel_name_snapshot', 'like', "%{$staffName}%"));
        }
    }

    private function applySettlementFilters($query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('item_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('packageEnrollment', fn ($packageQuery) => $packageQuery->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('sale', function ($saleQuery) use ($search) {
                        $saleQuery->where('code', 'like', "%{$search}%")
                            ->orWhereHas('booking', fn ($bookingQuery) => $bookingQuery->where('code', 'like', "%{$search}%"))
                            ->orWhereHas('customer', function ($customerQuery) use ($search) {
                                $customerQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('mobile_number', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if ($start = trim((string) $request->query('start_date', ''))) {
            $query->whereHas('sale', fn ($saleQuery) => $saleQuery->whereDate('sale_date', '>=', $start));
        }
        if ($end = trim((string) $request->query('end_date', ''))) {
            $query->whereHas('sale', fn ($saleQuery) => $saleQuery->whereDate('sale_date', '<=', $end));
        }

        if ($bookingId = trim((string) $request->query('booking_id', ''))) {
            $query->whereHas('sale.booking', fn ($bookingQuery) => $bookingQuery->where('code', 'like', "%{$bookingId}%"));
        }

        if ($packageId = trim((string) $request->query('package_id', ''))) {
            $query->whereHas('packageEnrollment', fn ($packageQuery) => $packageQuery->where('code', 'like', "%{$packageId}%"));
        }

        if ($customerName = trim((string) $request->query('customer_name', ''))) {
            $query->whereHas('sale.customer', function ($customerQuery) use ($customerName) {
                $this->applyPersonNameSearch($customerQuery, $customerName);
            });
        }

        if ($serviceName = trim((string) $request->query('service_name', ''))) {
            $query->where('item_name_snapshot', 'like', "%{$serviceName}%");
        }

        if ($staffName = trim((string) $request->query('staff_name', ''))) {
            $query->where('assigned_personnel_name_snapshot', 'like', "%{$staffName}%");
        }
    }

    private function applyPersonNameSearch($query, string $name): void
    {
        $terms = collect(preg_split('/\\s+/', trim($name)) ?: [])->filter()->values();
        foreach ($terms as $term) {
            $query->where(function ($nameQuery) use ($term) {
                $nameQuery->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
            });
        }
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') return;
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
    private function prewarmCustomerIdentities(int $businessId, array $customerIds): void
    {
        $this->customerClassifications->identitiesForCustomerIds($businessId, $customerIds);
    }

}
