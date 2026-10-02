<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\DataRepository;
use CommitteeManager\Http;

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
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
    Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
