<?php

namespace App\Services\Subscriptions;

use App\Models\Business;
use DomainException;
use JsonException;

class LicenseVerifier
{
    public function verify(string $token, Business $business): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'YDM1' || strlen($token) > 4096) {
            throw new DomainException('Invalid activation code.');
        }

        $key = base64_decode((string) config('subscriptions.public_key'), true);
        $signature = $this->decode($parts[2]);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, 'YDM1.'.$parts[1], $key)) {
            throw new DomainException('The activation code signature is invalid.');
        }

        try {
            $claims = json_decode($this->decode($parts[1]), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Invalid activation code data.');
        }

        if (! is_array($claims) || ($claims['version'] ?? null) !== 1
            || ! is_string($claims['tier'] ?? null) || ! array_key_exists($claims['tier'], config('subscriptions.plans'))
            || ! is_int($claims['revision'] ?? null) || $claims['revision'] < 1
            || ! is_string($claims['installation_id'] ?? null) || ! is_string($claims['business_code'] ?? null)
            || ! hash_equals((string) $business->license_installation_id, $claims['installation_id'])
            || ! hash_equals((string) $business->code, $claims['business_code'])) {
            throw new DomainException('This activation code does not match this installation and business.');
        }

        if (array_key_exists('expires_at', $claims) && $claims['expires_at'] !== null
            && (! is_int($claims['expires_at']) || $claims['expires_at'] <= 0)) {
            throw new DomainException('Invalid license expiry.');
        }

        return $claims;
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            throw new DomainException('Invalid activation code encoding.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
            throw new DomainException('Invalid activation code encoding.');
        }

        return $decoded;
    }
}
