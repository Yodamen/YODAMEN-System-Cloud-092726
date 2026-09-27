<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessBrandingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicBrandingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $business = Business::query()->where('status', 'ACTIVE')->orderBy('id')->first();
        if (! $business) {
            return response()->json(['branding' => $this->fallback()]);
        }
        $record = BusinessBrandingSetting::query()->where('business_id', $business->id)->first();

        return response()->json(['branding' => $this->settings($business, $record, $request)]);
    }

    public function logo(Business $business): StreamedResponse
    {
        $record = BusinessBrandingSetting::query()->where('business_id', $business->id)->firstOrFail();
        abort_unless($record->logo_source === 'UPLOAD' && $record->logo_path && Storage::disk('local')->exists($record->logo_path), 404);

        $mime = Storage::disk('local')->mimeType($record->logo_path) ?: 'image/png';

        return Storage::disk('local')->response(
            $record->logo_path,
            null,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    private function settings(Business $business, ?BusinessBrandingSetting $record, Request $request): array
    {
        return [
            'system_name' => $record?->system_name ?: 'YODAMEN Management System',
            'business_display_name' => $record?->business_display_name ?: $business->name,
            'subtitle' => $record?->subtitle ?: $business->legal_name,
            'logo_source' => $record?->logo_source ?: ($record?->logo_url ? 'URL' : 'NONE'),
            'logo_url' => $this->resolvedLogoUrl($business->id, $record, $request),
            'font_family' => $record?->font_family ?: 'INTER',
            'primary_color' => $record?->primary_color ?: '#c8a64b',
            'accent_color' => $record?->accent_color ?: '#c8a64b',
            'sidebar_color' => $record?->sidebar_color ?: '#141414',
            'login_label' => $record?->login_label ?: 'Operating System',
        ];
    }

    private function resolvedLogoUrl(int $businessId, ?BusinessBrandingSetting $record, Request $request): ?string
    {
        if (! $record) {
            return null;
        }

        if ($record->logo_source === 'UPLOAD' && $record->logo_path) {
            return $request->getSchemeAndHttpHost().'/api/v1/public/branding/logo/'.$businessId.'?v='.($record->updated_at?->timestamp ?? time());
        }

        return $record->logo_source === 'URL' ? $record->logo_url : null;
    }

    private function fallback(): array
    {
        return [
            'system_name' => 'YODAMEN Management System',
            'business_display_name' => 'YODAMEN',
            'subtitle' => 'Grooming Lounge',
            'logo_source' => 'NONE',
            'logo_url' => null,
            'font_family' => 'INTER',
            'primary_color' => '#c8a64b',
            'accent_color' => '#c8a64b',
            'sidebar_color' => '#141414',
            'login_label' => 'Operating System',
        ];
    }
}
