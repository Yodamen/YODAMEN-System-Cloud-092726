<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\StoreTransactionActionRequest;
use App\Models\Sale;
use App\Models\TransactionActionRequest;
use App\Services\Sales\TransactionActionControlService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionActionController extends Controller
{
    public function __construct(private readonly TransactionActionControlService $controls, private readonly SupportingDocumentService $documents) {}

    public function pending(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => $this->controls->pendingRequests($request->user()),
        ]);
    }

    public function context(Request $request, Sale $sale): JsonResponse
    {
        $data = $this->controls->context($request->user(), $sale);
        return response()->json([
            'sale' => $data['sale'],
            'reasons' => $data['reasons'],
            'personnel' => $data['personnel'],
            'retail_personnel' => $data['retail_personnel'] ?? [],
            'services' => $data['services'] ?? [],
            'retail_products' => $data['retail_products'] ?? [],
            'payment_methods' => $data['payment_methods'] ?? [],
            'service_credits' => $data['service_credits'] ?? [],
            'active_request' => $data['active_request'] ? $this->controls->requestResource($data['active_request']) : null,
        ]);
    }

    public function store(StoreTransactionActionRequest $request, Sale $sale): JsonResponse
    {
        try {
            $created = DB::transaction(function () use ($request,$sale) { $data=$request->validated(); $created=$this->controls->create($request->user(),$sale,$data); $this->documents->claimTokens($request->user(),$data['attachment_tokens']??[],'sales','TRANSACTION_ACTION_REQUEST',(int)$created->id,'TRANSACTION_ACTION_SUPPORT'); return $created; });
            return response()->json(['request' => $this->controls->requestResource($created)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, TransactionActionRequest $transactionActionRequest): JsonResponse
    {
        if ((int) $transactionActionRequest->business_id !== (int) $request->user()->business_id) abort(404);
        return response()->json(['request' => $this->controls->requestResource($transactionActionRequest)]);
    }

    public function approve(Request $request, TransactionActionRequest $transactionActionRequest): JsonResponse
    {
        $validated = $request->validate(['manager_remarks' => ['nullable', 'string', 'max:2000']]);
        try {
            $approved = $this->controls->approve($request->user(), $transactionActionRequest, $validated['manager_remarks'] ?? null);
            return response()->json(['request' => $this->controls->requestResource($approved)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reject(Request $request, TransactionActionRequest $transactionActionRequest, WorkflowReasonService $reasons): JsonResponse
    {
        $validated = $request->validate(['review_reason_reference_id' => ['required','integer'], 'manager_remarks' => ['required', 'string', 'max:2000']]);
        $reviewReason = $reasons->resolve($request->user(), (int) $validated['review_reason_reference_id'], WorkflowReasonCatalog::APPROVAL_REJECTION);
        try {
            $rejected = $this->controls->reject($request->user(), $transactionActionRequest, $validated['manager_remarks'], (int) $reviewReason->id);
            return response()->json(['request' => $this->controls->requestResource($rejected)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
