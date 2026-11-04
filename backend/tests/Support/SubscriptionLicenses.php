<?php

use App\Models\Business;

function subscriptionTestKeyPair(): string
{
    // Deterministic fixture only. Never used by the publisher or application config.
    return sodium_crypto_sign_seed_keypair(str_repeat("\x17", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

function subscriptionTestCode(Business $business, string $tier = 'premium', int $revision = 1, array $overrides = [], ?string $signingPair = null): string
{
    $pair = subscriptionTestKeyPair();
    config(['subscriptions.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $payload = $encode(json_encode(array_merge([
        'version' => 1, 'installation_id' => $business->license_installation_id,
        'business_code' => $business->code, 'tier' => $tier, 'revision' => $revision,
    ], $overrides), JSON_THROW_ON_ERROR));

    return 'YDM1.'.$payload.'.'.$encode(sodium_crypto_sign_detached('YDM1.'.$payload, sodium_crypto_sign_secretkey($signingPair ?? $pair)));
}
