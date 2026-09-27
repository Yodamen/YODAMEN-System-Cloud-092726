<?php

namespace App\Services\Inventory;

use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function index(User $actor, array $filters = []): array
    {
        $query = Supplier::query()
            ->where('business_id', $actor->business_id)
            ->with(['items' => fn ($q) => $q->select('retail_products.id','retail_products.sku','retail_products.name','retail_products.stock_type','retail_products.status')])
            ->orderBy('code_name')->orderBy('company_name');

        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $channel = trim((string) ($filters['channel'] ?? ''));
        $status = strtoupper(trim((string) ($filters['status'] ?? '')));

        if ($supplierId > 0) $query->whereKey($supplierId);
        if ($channel !== '') $query->where('channel', $channel);
        if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) $query->where('status', $status);

        return [
            'suppliers' => $query->get()->map(fn (Supplier $supplier) => $this->serialize($supplier))->values(),
            'options' => [
                'channels' => $this->channels((int) $actor->business_id),
                'suppliers' => Supplier::query()->where('business_id', $actor->business_id)->orderBy('company_name')->get(['id','company_name'])->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->company_name])->values(),
                'items' => $this->items((int) $actor->business_id),
            ],
        ];
    }

    public function create(User $actor, array $data): Supplier
    {
        return DB::transaction(function () use ($actor, $data) {
            $now = now();
            $supplier = Supplier::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('SUPPLIER:'.$actor->business_id, 'SUP-', 6, $actor->business_id),
                'company_name' => trim((string) $data['company_name']),
                'code_name' => $this->validatedCodeName($actor, (string) $data['code_name']),
                'contact_person' => $this->nullable($data['contact_person'] ?? null),
                'contact_number' => $this->nullable($data['contact_number'] ?? null),
                'channel' => $this->nullable($data['channel'] ?? null),
                'address' => $this->nullable($data['address'] ?? null),
                'store_link' => $this->nullable($data['store_link'] ?? null),
                'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->syncItems($actor, $supplier, $data['item_ids'] ?? []);
            $this->audit->record(action: 'CREATE_SUPPLIER', module: 'supplier-master', entityType: Supplier::class, entityId: $supplier->id, after: $this->serialize($supplier->fresh('items')), actor: $actor);
            return $supplier->fresh('items');
        });
    }

    public function update(User $actor, Supplier $supplier, array $data): Supplier
    {
        $this->assertOwned($actor, $supplier);
        return DB::transaction(function () use ($actor, $supplier, $data) {
            $before = $this->serialize($supplier->fresh('items'));
            $supplier->update([
                'company_name' => trim((string) $data['company_name']),
                'code_name' => $this->validatedCodeName($actor, (string) $data['code_name'], (int) $supplier->id),
                'contact_person' => $this->nullable($data['contact_person'] ?? null),
                'contact_number' => $this->nullable($data['contact_number'] ?? null),
                'channel' => $this->nullable($data['channel'] ?? null),
                'address' => $this->nullable($data['address'] ?? null),
                'store_link' => $this->nullable($data['store_link'] ?? null),
                'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
                'updated_by' => $actor->id,
            ]);
            $this->syncItems($actor, $supplier, $data['item_ids'] ?? []);
            $fresh = $supplier->fresh('items');
            $this->audit->record(action: 'EDIT_SUPPLIER', module: 'supplier-master', entityType: Supplier::class, entityId: $supplier->id, before: $before, after: $this->serialize($fresh), actor: $actor);
            return $fresh;
        });
    }

    public function toggle(User $actor, Supplier $supplier): Supplier
    {
        $this->assertOwned($actor, $supplier);
        $supplier->update(['status' => strtoupper((string) $supplier->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE', 'updated_by' => $actor->id]);
        return $supplier->fresh('items');
    }

    public function serialize(Supplier $supplier): array
    {
        $supplier->loadMissing(['items' => fn ($q) => $q->select('retail_products.id','retail_products.sku','retail_products.name','retail_products.stock_type','retail_products.status')]);
        return [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'company_name' => $supplier->company_name,
            'code_name' => $supplier->code_name,
            'contact_person' => $supplier->contact_person,
            'contact_number' => $supplier->contact_number,
            'channel' => $supplier->channel,
            'address' => $supplier->address,
            'store_link' => $supplier->store_link,
            'status' => $supplier->status,
            'item_ids' => $supplier->items->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'items' => $supplier->items->sortBy('name')->map(fn (RetailProduct $item) => [
                'id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'stock_type' => $item->stock_type, 'status' => $item->status,
            ])->values(),
        ];
    }


    private function validatedCodeName(User $actor, string $value, ?int $ignoreSupplierId = null): string
    {
        $value = trim($value);
        if ($value === '') throw new DomainException('Code Name is required.');

        $exists = Supplier::query()
            ->where('business_id', $actor->business_id)
            ->whereRaw('LOWER(code_name) = ?', [strtolower($value)])
            ->when($ignoreSupplierId, fn ($query) => $query->where('id', '!=', $ignoreSupplierId))
            ->exists();

        if ($exists) throw new DomainException('Code Name is already used by another supplier.');
        return substr($value, 0, 80);
    }

    private function syncItems(User $actor, Supplier $supplier, array $ids): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $valid = RetailProduct::query()->where('business_id', $actor->business_id)->whereIn('id', $ids)->pluck('id');
        if ($valid->count() !== $ids->count()) throw new DomainException('One or more supplier items are invalid.');
        $supplier->items()->sync($valid->all());
    }

    private function channels(int $businessId): Collection
    {
        $group = ReferenceGroup::query()->where('business_id', $businessId)->where('code', 'SUPPLIER_CHANNEL')->where('active', true)->first();
        if (! $group) return collect();
        return ReferenceValue::query()->where('reference_group_id', $group->id)->where('active', true)->orderBy('sort_order')->orderBy('display_value')->get()
            ->map(fn (ReferenceValue $v) => ['code' => $v->code, 'value' => $v->display_value])->values();
    }

    private function items(int $businessId): Collection
    {
        return RetailProduct::query()->where('business_id', $businessId)->orderBy('name')->get(['id','sku','name','stock_type','status'])
            ->map(fn (RetailProduct $p) => ['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'stock_type'=>$p->stock_type,'status'=>$p->status,'label'=>$p->name])->values();
    }

    private function assertOwned(User $actor, Supplier $supplier): void
    {
        if ((int) $supplier->business_id !== (int) $actor->business_id) abort(404);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
