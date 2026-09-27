<?php

namespace App\Services\Foundation;

use App\Models\AccountingPaymentMethodMapping;
use App\Models\ReferenceValue;
use App\Models\SupportingDocument;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportingDocumentService
{
    public const ENTITY_TYPES = [
        'EXPENSE', 'EXPENSE_REVERSAL_REQUEST', 'INVENTORY_RECEIPT', 'INVENTORY_RECEIPT_ACTION',
        'INVENTORY_STOCK_RETURN', 'INVENTORY_STOCK_RETURN_REFUND', 'INVENTORY_ADJUSTMENT',
        'INVENTORY_RECEIPT_ACTION_REFUND',
        'INVENTORY_COUNT', 'CASH_DEPOSIT', 'CASH_REMITTANCE', 'BUSINESS_FUNDING',
        'PETTY_CASH', 'PAYROLL_ADJUSTMENT', 'PAYROLL_ADJUSTMENT_REVERSAL', 'CASH_ADVANCE',
        'TRANSACTION_ACTION_REQUEST', 'SALE_CHECKOUT', 'BOOKING_PAYMENT',
    ];

    public const MODULES = [
        'expenses', 'stock-received', 'stock-returns', 'inventory-adjustments', 'inventory-count',
        'cash-deposits', 'cash-remittances', 'cash-management', 'petty-cash', 'payroll',
        'cash-advances', 'sales', 'bookings',
    ];

    public function uploadTemp(User $actor, UploadedFile $file, string $module, ?string $purpose = null): SupportingDocument
    {
        $module = $this->module($module);
        $token = (string) Str::uuid();
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName()) ?: 'document';
        $path = $file->storeAs(
            'supporting-documents/'.$actor->business_id.'/temp/'.now()->format('Ym'),
            $token.'-'.$safeName,
            config('filesystems.default'),
        );

        return SupportingDocument::query()->create([
            'business_id' => $actor->business_id,
            'module' => $module,
            'purpose' => $purpose ? trim($purpose) : null,
            'upload_token' => $token,
            'state' => 'TEMP',
            'disk' => config('filesystems.default'),
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function claimTokens(User $actor, array $tokens, string $module, string $entityType, int $entityId, ?string $purpose = null): Collection
    {
        $tokens = collect($tokens)->map(fn ($token) => trim((string) $token))->filter()->unique()->values();
        if ($tokens->isEmpty()) return collect();

        $module = $this->module($module);
        $entityType = $this->entityType($entityType);

        $rows = SupportingDocument::query()
            ->where('business_id', $actor->business_id)
            ->where('uploaded_by', $actor->id)
            ->where('state', 'TEMP')
            ->whereIn('upload_token', $tokens)
            ->lockForUpdate()
            ->get();

        if ($rows->count() !== $tokens->count()) {
            throw new DomainException('One or more supporting documents are missing, already used, or unavailable. Re-upload the file and try again.');
        }

        $now = now();
        foreach ($rows as $row) {
            if ($row->module !== $module) {
                throw new DomainException('A supporting document was uploaded for a different module. Re-upload it from this transaction.');
            }
            $row->update([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'purpose' => $purpose ? trim($purpose) : $row->purpose,
                'state' => 'ATTACHED',
                'attached_at' => $now,
            ]);
        }

        return $rows->fresh();
    }

    public function storeForEntity(User $actor, UploadedFile $file, string $module, string $entityType, int $entityId, ?string $purpose = null): SupportingDocument
    {
        $row = $this->uploadTemp($actor, $file, $module, $purpose);
        return $this->claimTokens($actor, [$row->upload_token], $module, $entityType, $entityId, $purpose)->firstOrFail();
    }

    public function hasAttached(int $businessId, string $entityType, int $entityId): bool
    {
        return SupportingDocument::query()
            ->where('business_id', $businessId)
            ->where('entity_type', $this->entityType($entityType))
            ->where('entity_id', $entityId)
            ->where('state', 'ATTACHED')
            ->exists();
    }

    public function listForEntity(User $actor, string $entityType, int $entityId): Collection
    {
        return SupportingDocument::query()
            ->where('business_id', $actor->business_id)
            ->where('entity_type', $this->entityType($entityType))
            ->where('entity_id', $entityId)
            ->where('state', 'ATTACHED')
            ->with('uploader:id,name')
            ->orderBy('id')
            ->get();
    }

    public function serialize(SupportingDocument $row): array
    {
        return [
            'id' => $row->id,
            'module' => $row->module,
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'purpose' => $row->purpose,
            'name' => $row->original_name,
            'mime_type' => $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'uploaded_by' => $row->uploader?->name,
            'uploaded_at' => $row->created_at?->toIso8601String(),
        ];
    }


    public function canViewEntityType(User $actor, string $entityType): bool
    {
        $entityType = $this->entityType($entityType);
        $module = match ($entityType) {
            'EXPENSE', 'EXPENSE_REVERSAL_REQUEST' => 'expenses',
            'INVENTORY_RECEIPT', 'INVENTORY_RECEIPT_ACTION', 'INVENTORY_RECEIPT_ACTION_REFUND' => 'stock-received',
            'INVENTORY_STOCK_RETURN', 'INVENTORY_STOCK_RETURN_REFUND' => 'stock-returns',
            'INVENTORY_ADJUSTMENT' => 'inventory-adjustments',
            'INVENTORY_COUNT' => 'inventory-count',
            'CASH_DEPOSIT', 'CASH_REMITTANCE' => 'cash-deposits',
            'BUSINESS_FUNDING' => 'cash-management',
            'PETTY_CASH' => 'petty-cash',
            'PAYROLL_ADJUSTMENT', 'PAYROLL_ADJUSTMENT_REVERSAL' => 'payroll',
            'CASH_ADVANCE' => 'cash-advances',
            'TRANSACTION_ACTION_REQUEST' => 'sales',
            'SALE_CHECKOUT' => 'sales',
            'BOOKING_PAYMENT' => 'bookings',
        };

        if ($entityType === 'SALE_CHECKOUT') {
            return $actor->hasPermission('sales', 'VIEW') || $actor->hasPermission('retail-pos', 'VIEW');
        }

        return $actor->hasPermission($module, 'VIEW');
    }

    public function canDownload(User $actor, SupportingDocument $row): bool
    {
        if ((int) $row->business_id !== (int) $actor->business_id) return false;
        if ($row->state !== 'ATTACHED') return (int) $row->uploaded_by === (int) $actor->id;
        if (! $row->entity_type || ! $this->canViewEntityType($actor, $row->entity_type)) return false;

        $entityType = $this->entityType($row->entity_type);
        if ($entityType === 'SALE_CHECKOUT') {
            return $actor->hasPermission('sales', 'DOWNLOAD') || $actor->hasPermission('retail-pos', 'DOWNLOAD');
        }

        $module = match ($entityType) {
            'EXPENSE', 'EXPENSE_REVERSAL_REQUEST' => 'expenses',
            'INVENTORY_RECEIPT', 'INVENTORY_RECEIPT_ACTION', 'INVENTORY_RECEIPT_ACTION_REFUND' => 'stock-received',
            'INVENTORY_STOCK_RETURN', 'INVENTORY_STOCK_RETURN_REFUND' => 'stock-returns',
            'INVENTORY_ADJUSTMENT' => 'inventory-adjustments',
            'INVENTORY_COUNT' => 'inventory-count',
            'CASH_DEPOSIT', 'CASH_REMITTANCE' => 'cash-deposits',
            'BUSINESS_FUNDING' => 'cash-management',
            'PETTY_CASH' => 'petty-cash',
            'PAYROLL_ADJUSTMENT', 'PAYROLL_ADJUSTMENT_REVERSAL' => 'payroll',
            'CASH_ADVANCE' => 'cash-advances',
            'TRANSACTION_ACTION_REQUEST' => 'sales',
            'BOOKING_PAYMENT' => 'bookings',
        };

        return $actor->hasPermission($module, 'DOWNLOAD');
    }


    public function assertPaymentProofForMethods(User $actor, array $paymentMethodIds, array $tokens, string $context = 'Payment'): void
    {
        $ids = collect($paymentMethodIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) return;

        $methods = ReferenceValue::query()
            ->whereIn('id', $ids)
            ->whereHas('group', fn ($query) => $query
                ->where('business_id', $actor->business_id)
                ->where('code', 'PAYMENT_METHOD'))
            ->get(['id', 'code', 'display_value']);

        if ($methods->count() !== $ids->count()) {
            throw new DomainException('Select a valid Payment Method.');
        }

        $mappings = AccountingPaymentMethodMapping::query()
            ->with('cashAccount:id,system_key,name')
            ->where('business_id', $actor->business_id)
            ->whereIn('payment_method_reference_id', $ids)
            ->get()
            ->keyBy('payment_method_reference_id');

        $requiresProof = $methods->contains(function (ReferenceValue $method) use ($mappings) {
            return ! $this->isCashPaymentMethod($method, $mappings->get((int) $method->id));
        });
        if (! $requiresProof) return;

        $hasProof = collect($tokens)->map(fn ($token) => trim((string) $token))->filter()->isNotEmpty();
        if (! $hasProof) {
            throw new DomainException($context.' proof is required for non-cash payments such as GCash, bank transfer, e-wallet or card. Cash payments are exempt.');
        }
    }

    private function isCashPaymentMethod(ReferenceValue $method, ?AccountingPaymentMethodMapping $mapping = null): bool
    {
        $code = strtoupper(trim((string) $method->code));
        $name = strtoupper(trim((string) $method->display_value));

        if (in_array($code, ['CASH', 'CASH_PAYMENT', 'CASH_ON_HAND'], true)
            || in_array($name, ['CASH', 'CASH PAYMENT', 'CASH ON HAND'], true)) {
            return true;
        }

        // Accounting mapping is the authoritative classification for custom payment-method labels.
        // Anything mapped to Cash on Hand is cash; bank/e-wallet/card clearing mappings remain non-cash.
        return strtoupper(trim((string) ($mapping?->cashAccount?->system_key ?? ''))) === 'CASH_ON_HAND';
    }

    public function fileExists(SupportingDocument $row): bool
    {
        return Storage::disk($row->disk)->exists($row->path);
    }

    private function module(string $module): string
    {
        $module = strtolower(trim($module));
        if (! in_array($module, self::MODULES, true)) throw new DomainException('Unsupported supporting-document module.');
        return $module;
    }

    private function entityType(string $entityType): string
    {
        $entityType = strtoupper(trim($entityType));
        if (! in_array($entityType, self::ENTITY_TYPES, true)) throw new DomainException('Unsupported supporting-document record type.');
        return $entityType;
    }
}
