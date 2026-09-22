<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;

Config::load();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$signedRequest = isset($_POST['signed_request']) ? (string) $_POST['signed_request'] : '';
if ($signedRequest === '' || !str_contains($signedRequest, '.')) {
    http_response_code(403);
    exit;
}

[$encodedSig, $payload] = explode('.', $signedRequest, 2);
$secret = Config::get('INSTAGRAM_APP_SECRET');
$expected = hash_hmac('sha256', $payload, $secret, true);
$signature = base64UrlDecode($encodedSig);

if ($signature === null || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit;
}

$decodedPayload = base64UrlDecode($payload);
if ($decodedPayload === null) {
    http_response_code(403);
    exit;
}

$data = json_decode($decodedPayload, true);
if (!is_array($data) || !isset($data['user_id'])) {
    http_response_code(403);
    exit;
}

$users = new UserRepository(Db::pdo(), Crypto::fromConfig());
$users->markInstagramRevokedByIgUserId((string) $data['user_id']);

http_response_code(200);
echo 'OK';

/**
 * @return string|null
 */
function base64UrlDecode(string $input): ?string
{
    $remainder = strlen($input) % 4;
    if ($remainder > 0) {
        $input .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($input, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}
