<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Security\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessSetupController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business()->firstOrFail();

        return response()->json([
            'business' => $business,
            'branches' => Branch::query()->where('business_id', $business->id)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $business = $request->user()->business()->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $before = $business->toArray();
        $business->update([
            'name' => trim($data['name']),
            'legal_name' => trim((string) ($data['legal_name'] ?? '')) ?: null,
            'timezone' => trim($data['timezone']),
            'currency' => strtoupper(trim($data['currency'])),
        ]);
        $audit->record('UPDATE_BUSINESS_SETUP', 'business-setup', $business::class, $business->id, $before, $business->fresh()->toArray(), actor: $request->user());

        return response()->json(['message' => 'Business setup updated.', 'business' => $business->fresh()]);
    }

    public function storeBranch(Request $request, AuditService $audit): JsonResponse
    {
        $business = $request->user()->business()->firstOrFail();
        $data = $request->validate($this->branchRules());
        $branch = Branch::query()->create([
            'business_id' => $business->id,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'short_code' => trim((string) ($data['short_code'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'timezone' => trim((string) ($data['timezone'] ?? '')) ?: null,
            'status' => 'ACTIVE',
        ]);
        $audit->record('CREATE_BRANCH', 'business-setup', $branch::class, $branch->id, after: $branch->toArray(), actor: $request->user());

        return response()->json(['message' => 'Branch created.', 'branch' => $branch], 201);
    }

    public function updateBranch(Request $request, Branch $branch, AuditService $audit): JsonResponse
    {
        $this->assertBranch($request, $branch);
        $data = $request->validate($this->branchRules($branch->id));
        $before = $branch->toArray();
        $branch->update([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'short_code' => trim((string) ($data['short_code'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'timezone' => trim((string) ($data['timezone'] ?? '')) ?: null,
        ]);
        $audit->record('UPDATE_BRANCH', 'business-setup', $branch::class, $branch->id, $before, $branch->fresh()->toArray(), actor: $request->user());

        return response()->json(['message' => 'Branch updated.', 'branch' => $branch->fresh()]);
    }

    public function toggleBranch(Request $request, Branch $branch, AuditService $audit): JsonResponse
    {
        $this->assertBranch($request, $branch);
        $before = $branch->toArray();
        $branch->update(['status' => strtoupper((string) $branch->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE']);
        $audit->record('TOGGLE_BRANCH', 'business-setup', $branch::class, $branch->id, $before, $branch->fresh()->toArray(), actor: $request->user());

        return response()->json(['message' => 'Branch status updated.', 'branch' => $branch->fresh()]);
    }

    private function assertBranch(Request $request, Branch $branch): void
    {
        if ((int) $branch->business_id !== (int) $request->user()->business_id) abort(404);
    }

    private function branchRules(?int $branchId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($branchId)],
            'name' => ['required', 'string', 'max:255'],
            'short_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
