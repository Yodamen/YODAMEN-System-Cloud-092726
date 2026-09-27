<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Services\Inventory\InventoryControlService;
use App\Services\Foundation\SupportingDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryControlController extends Controller
{
    public function receipts(Request $request, InventoryControlService $service): JsonResponse
    {
        return response()->json($service->receipts($request->user(), $request->only(['branch_id', 'supplier_id', 'product_id', 'from', 'to', 'search'])));
    }

    public function receive(Request $request, InventoryControlService $service, SupportingDocumentService $documents): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'received_date' => ['required', 'date'],
            'supplier_id' => ['required', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:180'],
            'reference_no' => ['nullable', 'string', 'max:180'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['nullable', 'in:PAID,UNPAID'],
            'payment_splits' => ['nullable','array'],
            'payment_splits.*.funding_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method_reference_id' => ['nullable','integer'],
            'payment_splits.*.amount' => ['required_with:payment_splits','numeric','gt:0'],
            'payment_splits.*.reference' => ['nullable','string','max:180'],
            // Backward-compatible single-payment API input. Current UI submits payment_splits.
            'payment_method_reference_id' => ['nullable','integer'],
            'payment_reference' => ['nullable','string','max:180'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'attachment_tokens' => ['required', 'array', 'min:1', 'max:10'],
            'attachment_tokens.*' => ['required', 'uuid', 'distinct'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.retail_product_id' => ['required', 'integer', 'distinct'],
            'lines.*.purchase_quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.purchase_uom' => ['required', 'string', 'max:40'],
            'lines.*.conversion_factor' => ['required', 'numeric', 'gt:0'],
            'lines.*.purchase_unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.update_product_conversion' => ['nullable', 'boolean'],
        ]);

        try {
            $receipt = DB::transaction(function () use ($request, $data, $service, $documents) {
                $receipt = $service->receive($request->user(), $data);
                $documents->claimTokens($request->user(), $data['attachment_tokens'], 'stock-received', 'INVENTORY_RECEIPT', (int) $receipt->id, 'STOCK_RECEIVED_SUPPORT');
                return $receipt;
            });
            return response()->json(['message' => 'Stock received and inventory balances updated.', 'receipt' => $service->serializeReceipt($receipt)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function counts(Request $request, InventoryControlService $service): JsonResponse
    {
        return response()->json($service->counts($request->user(), $request->only(['branch_id', 'from', 'to', 'status', 'count_scope', 'category'])));
    }

    public function startCount(Request $request, InventoryControlService $service, SupportingDocumentService $documents): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'count_date' => ['required', 'date'],
            'scope_type' => ['required', 'in:FULL,CATEGORY,SELECTED,STOCK_TYPE,CATEGORIES'],
            'stock_type' => ['nullable', 'in:INVENTORY,NON_INVENTORY,ASSET'],
            'category' => ['nullable', 'string', 'max:120'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120', 'distinct'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'attachment_tokens' => ['sometimes', 'array', 'max:10'],
            'attachment_tokens.*' => ['required', 'uuid', 'distinct'],
        ]);

        try {
            $count = DB::transaction(function () use ($request, $data, $service, $documents) {
                $count = $service->startCount($request->user(), $data);
                $documents->claimTokens($request->user(), $data['attachment_tokens'] ?? [], 'inventory-count', 'INVENTORY_COUNT', (int) $count->id, 'AUDIT_COUNT_SUPPORT');
                return $count;
            });
            return response()->json(['message' => 'Audit count started.', 'count' => $service->countDetail($request->user(), $count)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function countDetail(Request $request, InventoryCount $inventoryCount, InventoryControlService $service): JsonResponse
    {
        try {
            return response()->json(['count' => $service->countDetail($request->user(), $inventoryCount)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function saveCount(Request $request, InventoryCount $inventoryCount, InventoryControlService $service): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.actual_quantity' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $count = $service->saveCount($request->user(), $inventoryCount, $data);
            return response()->json(['message' => 'Audit count draft saved.', 'count' => $service->countDetail($request->user(), $count)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function submitCount(Request $request, InventoryCount $inventoryCount, InventoryControlService $service): JsonResponse
    {
        try {
            $count = $service->submitCount($request->user(), $inventoryCount);
            return response()->json(['message' => 'Audit count submitted for approval.', 'count' => $service->countDetail($request->user(), $count)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function approveCount(Request $request, InventoryCount $inventoryCount, InventoryControlService $service): JsonResponse
    {
        try {
            $count = $service->approveCount($request->user(), $inventoryCount);
            return response()->json(['message' => 'Audit count approved and stock variances posted.', 'count' => $service->countDetail($request->user(), $count)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function cancelCount(Request $request, InventoryCount $inventoryCount, InventoryControlService $service): JsonResponse
    {
        try {
            $count = $service->cancelCount($request->user(), $inventoryCount);
            return response()->json(['message' => 'Audit count cancelled.', 'count' => $service->countDetail($request->user(), $count)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
