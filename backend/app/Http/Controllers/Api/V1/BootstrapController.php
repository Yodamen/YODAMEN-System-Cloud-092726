<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BusinessBrandingSetting;
use App\Services\Foundation\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, BusinessClock $clock): JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'business', 'defaultBranch']);

        $branches = $user->business_id
            ? Branch::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'short_code', 'timezone'])
            : collect();

        $branding = $user->business_id
            ? BusinessBrandingSetting::query()->where('business_id', $user->business_id)->first()
            : null;

        return response()->json([
            'system' => [
                'name' => config('app.name'),
                'timezone' => $clock->timezone(),
                'business_date' => $clock->today(),
                'business_time' => $clock->now()->toIso8601String(),
            ],
            'business' => $user->business ? [
                'code' => $user->business->code,
                'name' => $user->business->name,
                'legal_name' => $user->business->legal_name,
                'timezone' => $user->business->timezone,
                'currency' => $user->business->currency,
            ] : null,
            'branches' => $branches,
            'branding' => $user->business ? [
                'system_name' => $branding?->system_name ?: 'YODAMEN Management System',
                'business_display_name' => $branding?->business_display_name ?: $user->business->name,
                'subtitle' => $branding?->subtitle ?: $user->business->legal_name,
                'logo_source' => $branding?->logo_source ?: ($branding?->logo_url ? 'URL' : 'NONE'),
                'logo_url' => $this->resolvedLogoUrl((int) $user->business_id, $branding, $request),
                'font_family' => $branding?->font_family ?: 'INTER',
                'primary_color' => $branding?->primary_color ?: '#c8a64b',
                'accent_color' => $branding?->accent_color ?: '#c8a64b',
                'sidebar_color' => $branding?->sidebar_color ?: '#141414',
                'login_label' => $branding?->login_label ?: 'Operating System',
            ] : null,
            'permissions' => $user->permissionCodes(),
        ]);
    }

    private function resolvedLogoUrl(int $businessId, ?BusinessBrandingSetting $branding, Request $request): ?string
    {
        if (! $branding) {
            return null;
        }

        if ($branding->logo_source === 'UPLOAD' && $branding->logo_path) {
            return $request->getSchemeAndHttpHost().'/api/v1/public/branding/logo/'.$businessId.'?v='.($branding->updated_at?->timestamp ?? time());
        }

        return $branding->logo_source === 'URL' ? $branding->logo_url : null;
    }
}
