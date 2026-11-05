<?php

namespace App\Services\Subscriptions;

use App\Models\Business;
use App\Models\User;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(private readonly LicenseVerifier $licenses) {}

    public function snapshot(?Business $business): array
    {
        $tier = 'premium';
        $limit = (int) config('subscriptions.trial_limit');
        $used = (int) ($business?->trial_uses ?? 0);
        $status = $used >= $limit ? 'trial_exhausted' : 'trial';
        $readOnly = $used >= $limit;
        if ($business?->license_token) {
            try {
                $claims = $this->licenses->verify($business->license_token, $business);
                if ($claims['revision'] !== (int) $business->license_revision) {
                    throw new DomainException('License revision mismatch.');
                }
                $tier = $claims['tier'];
                // All paid tiers are permanent, including previously issued dated codes.
                $status = 'active';
                $readOnly = false;
            } catch (DomainException) {
                $status = 'invalid';
                $tier = 'basic';
                $readOnly = true;
            }
        }

        $plan = config('subscriptions.plans.'.$tier);
        $modules = config('subscriptions.core_modules');
        foreach ($plan['features'] as $feature) {
            $modules = array_merge($modules, config('subscriptions.features.'.$feature.'.modules'));
        }

        return [
            'tier' => $tier,
            'name' => $plan['name'],
            'status' => $status,
            'is_read_only' => $readOnly,
            'expires_at' => null,
            'trial_limit' => $limit,
            'trial_used' => $used,
            'trial_remaining' => max(0, $limit - $used),
            'features' => $plan['features'],
            'modules' => array_values(array_unique($modules)),
            'feature_catalog' => collect(config('subscriptions.features'))->map(fn ($feature, $key) => [
                'code' => $key,
                'name' => $feature['name'],
                'included' => in_array($key, $plan['features'], true),
            ])->values()->all(),
        ];
    }

    public function allowsModule(?Business $business, string $module): bool
    {
        return $business !== null && in_array($module, $this->snapshot($business)['modules'], true);
    }

    public function activate(User $actor, string $token): Business
    {
        return DB::transaction(function () use ($actor, $token) {
            $business = Business::query()->lockForUpdate()->findOrFail($actor->business_id);
            $claims = $this->licenses->verify($token, $business);
            if ($business->license_token === $token && (int) $business->license_revision === $claims['revision']) {
                return $business;
            }
            if ($claims['revision'] <= (int) $business->license_revision) {
                throw new DomainException('This activation code has been superseded. Request a newer code.');
            }

            $before = $this->snapshot($business);
            $business->forceFill(['license_token' => $token, 'license_revision' => $claims['revision']])->save();
            app(AuditService::class)->record(
                action: 'ACTIVATE_SUBSCRIPTION', module: 'subscription',
                entityType: Business::class, entityId: $business->code,
                before: ['tier' => $before['tier']],
                after: ['tier' => $claims['tier'], 'revision' => $claims['revision']],
                actor: $actor,
            );

            return $business;
        });
    }
}
