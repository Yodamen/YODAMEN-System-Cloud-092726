<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessBrandingSetting;
use App\Services\Security\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BrandingController extends Controller
{
    private const FONT_FAMILIES = [
        'INTER',
        'SYSTEM',
        'ARIAL',
        'HELVETICA',
        'TREBUCHET_MS',
        'VERDANA',
        'TAHOMA',
        'GEORGIA',
        'TIMES_NEW_ROMAN',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'branding' => $this->settings((int) $request->user()->business_id, $request),
            'font_options' => $this->fontOptions(),
            'logo_recommendation' => [
                'width' => 800,
                'height' => 200,
                'ratio' => '4:1',
                'max_mb' => 5,
                'formats' => ['PNG', 'JPG', 'WebP'],
            ],
        ]);
    }

    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'system_name' => ['required', 'string', 'max:255'],
            'business_display_name' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'logo_source' => ['nullable', Rule::in(['NONE', 'UPLOAD', 'URL'])],
            'logo_url' => ['nullable', 'url', 'max:2000'],
            'font_family' => ['nullable', Rule::in(self::FONT_FAMILIES)],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sidebar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'login_label' => ['required', 'string', 'max:120'],
        ]);

        $record = BusinessBrandingSetting::query()->firstOrCreate(
            ['business_id' => $request->user()->business_id],
            [
                'system_name' => 'YODAMEN Management System',
                'font_family' => 'INTER',
                'primary_color' => '#c8a64b',
                'accent_color' => '#c8a64b',
                'sidebar_color' => '#141414',
                'login_label' => 'Operating System',
            ],
        );

        $before = $record->toArray();
        if (array_key_exists('logo_source', $data)) {
            $source = strtoupper((string) $data['logo_source']);
        } elseif (! empty($data['logo_url'])) {
            $source = 'URL';
        } else {
            $source = strtoupper((string) ($record->logo_source ?: 'NONE'));
        }

        if ($source === 'URL' && empty($data['logo_url'])) {
            return response()->json([
                'message' => 'Enter a Logo URL or switch Logo Source to Upload Image / No Logo.',
                'errors' => ['logo_url' => ['Logo URL is required when Logo Source is URL.']],
            ], 422);
        }

        if ($source !== 'UPLOAD' && $record->logo_path) {
            Storage::disk(config('filesystems.default'))->delete($record->logo_path);
            $record->logo_path = null;
        }

        $record->fill([
            'system_name' => trim($data['system_name']),
            'business_display_name' => trim((string) ($data['business_display_name'] ?? '')) ?: null,
            'subtitle' => trim((string) ($data['subtitle'] ?? '')) ?: null,
            'logo_source' => $source,
            'logo_url' => $source === 'URL' ? (trim((string) ($data['logo_url'] ?? '')) ?: null) : null,
            'font_family' => strtoupper((string) ($data['font_family'] ?? $record->font_family ?? 'INTER')),
            'primary_color' => strtolower((string) ($data['primary_color'] ?? $record->primary_color ?? '#c8a64b')),
            'accent_color' => strtolower($data['accent_color']),
            'sidebar_color' => strtolower((string) ($data['sidebar_color'] ?? $record->sidebar_color ?? '#141414')),
            'login_label' => trim($data['login_label']),
        ]);

        if ($source === 'NONE') {
            $record->logo_path = null;
            $record->logo_url = null;
        }

        $record->save();

        $audit->record(
            'UPDATE_BRANDING',
            'branding',
            $record::class,
            $record->id,
            $before,
            $record->fresh()->toArray(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Branding updated.',
            'branding' => $this->settings((int) $request->user()->business_id, $request),
        ]);
    }

    public function uploadLogo(Request $request, AuditService $audit): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $record = BusinessBrandingSetting::query()->firstOrCreate(
            ['business_id' => $businessId],
            [
                'system_name' => 'YODAMEN Management System',
                'font_family' => 'INTER',
                'primary_color' => '#c8a64b',
                'accent_color' => '#c8a64b',
                'sidebar_color' => '#141414',
                'login_label' => 'Operating System',
            ],
        );

        $before = $record->toArray();
        if ($record->logo_path) {
            Storage::disk(config('filesystems.default'))->delete($record->logo_path);
        }

        $file = $request->file('logo');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $path = $file->storeAs(
            "branding/{$businessId}",
            'logo.'.preg_replace('/[^a-z0-9]/', '', $extension),
            config('filesystems.default'),
        );

        $record->update([
            'logo_source' => 'UPLOAD',
            'logo_path' => $path,
            'logo_url' => null,
        ]);

        $audit->record(
            'UPLOAD_BRANDING_LOGO',
            'branding',
            $record::class,
            $record->id,
            $before,
            $record->fresh()->toArray(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Logo uploaded.',
            'branding' => $this->settings($businessId, $request),
        ]);
    }

    private function settings(int $businessId, Request $request): array
    {
        $business = \App\Models\Business::query()->findOrFail($businessId);
        $record = BusinessBrandingSetting::query()->where('business_id', $businessId)->first();

        return [
            'system_name' => $record?->system_name ?: 'YODAMEN Management System',
            'business_display_name' => $record?->business_display_name ?: $business->name,
            'subtitle' => $record?->subtitle ?: $business->legal_name,
            'logo_source' => $record?->logo_source ?: ($record?->logo_url ? 'URL' : 'NONE'),
            'logo_url' => $this->resolvedLogoUrl($record, $request),
            'external_logo_url' => $record?->logo_url,
            'font_family' => $record?->font_family ?: 'INTER',
            'primary_color' => $record?->primary_color ?: '#c8a64b',
            'accent_color' => $record?->accent_color ?: '#c8a64b',
            'sidebar_color' => $record?->sidebar_color ?: '#141414',
            'login_label' => $record?->login_label ?: 'Operating System',
        ];
    }

    private function resolvedLogoUrl(?BusinessBrandingSetting $record, Request $request): ?string
    {
        if (! $record) {
            return null;
        }

        if ($record->logo_source === 'UPLOAD' && $record->logo_path) {
            return $request->getSchemeAndHttpHost().'/api/v1/public/branding/logo/'.$record->business_id.'?v='.($record->updated_at?->timestamp ?? time());
        }

        return $record->logo_source === 'URL' ? $record->logo_url : null;
    }

    private function fontOptions(): array
    {
        return [
            ['value' => 'INTER', 'label' => 'Inter / Modern Sans'],
            ['value' => 'SYSTEM', 'label' => 'System UI'],
            ['value' => 'ARIAL', 'label' => 'Arial'],
            ['value' => 'HELVETICA', 'label' => 'Helvetica'],
            ['value' => 'TREBUCHET_MS', 'label' => 'Trebuchet MS'],
            ['value' => 'VERDANA', 'label' => 'Verdana'],
            ['value' => 'TAHOMA', 'label' => 'Tahoma'],
            ['value' => 'GEORGIA', 'label' => 'Georgia'],
            ['value' => 'TIMES_NEW_ROMAN', 'label' => 'Times New Roman'],
        ];
    }
}
