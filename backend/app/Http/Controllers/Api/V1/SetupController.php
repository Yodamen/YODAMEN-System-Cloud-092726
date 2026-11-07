<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Foundation\BrowserSetupService;
use App\Services\Subscriptions\SubscriptionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SetupController extends Controller
{
    public function show(BrowserSetupService $setup): JsonResponse
    {
        return response()->json($setup->status())->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, BrowserSetupService $setup, SubscriptionService $subscriptions): JsonResponse
    {
        $input = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_code' => ['required', 'string', 'max:20'],
            'branch_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50'],
            'display_name' => ['required', 'string', 'max:255'],
            'access_code' => ['required', 'string', 'confirmed', 'max:72'],
        ]);
        try { $business = $setup->complete(trim((string) $request->header('X-Yodamen-Setup-Token')), $input); }
        catch (DomainException $exception) {
            $code = $exception->getMessage();
            $message = match ($code) {
                'SETUP_CLOSED' => 'First-time setup is closed. Sign in to the existing account.',
                'SETUP_TOKEN_EXPIRED' => 'The setup code expired. Rerun the installer to get a fresh code.',
                default => 'The setup code is invalid. Copy it from the installer.',
            };
            return response()->json(['message' => $message, 'code' => $code], $code === 'SETUP_CLOSED' ? 409 : 403);
        }
        $admin = User::query()->where('business_id', $business->id)->whereHas('role', fn ($q) => $q->where('is_super_admin', true))->firstOrFail();
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        return response()->json([
            'message' => 'Your business and Super Admin account are ready.',
            'business_code' => $business->code, 'installation_id' => $business->license_installation_id,
            'subscription' => $subscriptions->snapshot($business),
        ], 201)->header('Cache-Control', 'no-store');
    }
}
