<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\DataRepository;
use CommitteeManager\Http;

(function () {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $host = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
    $serverHost = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    $envAllowed = trim((string) ($_ENV['ALLOWED_ORIGIN'] ?? (getenv('ALLOWED_ORIGIN') ?: '')));
    if ($host !== '' && $host === $serverHost) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } elseif ($envAllowed !== '' && $origin === $envAllowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
})();
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (Http::method() !== 'GET') {
    Http::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $data = (new DataRepository())->loadPublicInfo();
    Http::json($data);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'error' => Http::sanitizeError($e)], 500);
}
