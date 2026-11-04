<?php

// Publisher utility. The customer's server never needs the signing key.
declare(strict_types=1);
$options = getopt('', ['key-file:', 'installation:', 'business:', 'tier:', 'revision:', 'expires:', 'out:']);
$fail = function (string $message): never { fwrite(STDERR, $message."\n"); exit(1); };
foreach (['key-file', 'installation', 'business', 'tier', 'revision', 'expires'] as $name) {
    if (! isset($options[$name]) || ! is_string($options[$name])) $fail('Required option: --'.$name);
}
if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $options['installation'])) $fail('Invalid installation ID.');
if (! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $options['business'])) $fail('Invalid business code.');
if (! in_array($options['tier'], ['basic', 'standard', 'premium'], true)) $fail('Tier must be basic, standard or premium.');
$revision = filter_var($options['revision'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($revision === false) $fail('Revision must be a positive integer higher than the installed revision.');
$expires = null;
if ($options['expires'] !== 'never') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $options['expires'], new DateTimeZone('Asia/Manila'));
    if (! $date || $date->format('Y-m-d') !== $options['expires']) $fail('Expiry must be YYYY-MM-DD or never.');
    $expires = $date->modify('+1 day')->getTimestamp();
    if ($expires <= time()) $fail('Expiry must be today or a future date.');
}
if (! is_file($options['key-file'])) $fail('Signing key file does not exist.');
$secret = base64_decode(trim(file_get_contents($options['key-file'])), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) $fail('Invalid signing key.');
$config = require __DIR__.'/../backend/config/subscriptions.php';
if (! hash_equals($config['public_key'], base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)))) $fail('Signing key does not match the public key shipped in this application.');
$encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$payload = $encode(json_encode([
    'version' => 1, 'installation_id' => $options['installation'],
    'business_code' => $options['business'], 'tier' => $options['tier'],
    'revision' => $revision, 'expires_at' => $expires,
], JSON_THROW_ON_ERROR));
$token = 'YDM1.'.$payload.'.'.$encode(sodium_crypto_sign_detached('YDM1.'.$payload, $secret));
if (isset($options['out'])) {
    umask(0077);
    $handle = fopen($options['out'], 'x');
    if ($handle === false) $fail('Cannot create output file or file already exists.');
    fwrite($handle, $token."\n"); fclose($handle);
    echo "Activation code saved.\n";
} else echo $token."\n";
