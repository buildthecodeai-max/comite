<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Auth;
use CommitteeManager\DataRepository;
use CommitteeManager\Http;

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $user = Auth::requireLogin();
    $repo = new DataRepository();

    if (Http::method() === 'GET') {
        if ($user['role'] === 'user' && !empty($user['memberId'])) {
            Http::json($repo->loadForMember((int) $user['memberId']));
        }
        Auth::requireAdmin();
        Http::json($repo->loadAll());
    }

    if (Http::method() !== 'POST') {
        Http::json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $body = Http::readJsonBody();

    if ($user['role'] === 'admin') {
        if (!empty($body['resetAll'])) {
            $data = $repo->resetToDefaults();
            Http::json(['ok' => true, 'data' => $data]);
        }

        if (($body['action'] ?? '') === 'permanentDelete') {
            $saved = $repo->permanentDeleteMembers($body['memberIds'] ?? []);
            Http::json(['ok' => true, 'data' => $saved]);
        }

        $saved = $repo->saveAll($body);
        Http::json(['ok' => true, 'data' => $saved]);
    }

    if ($user['role'] === 'user' && !empty($user['memberId'])) {
        $current = $repo->loadAll();
        if (!$repo->payloadMatchesMemberSelfEdit($body, $current, (int) $user['memberId'])) {
            Http::json(['ok' => false, 'error' => 'Members may only update their own PIN and preferred month'], 403);
        }

        $saved = $repo->saveMemberSelf((int) $user['memberId'], $body, $current);
        Http::json(['ok' => true, 'data' => $saved]);
    }

    Http::json(['ok' => false, 'error' => 'Forbidden'], 403);
} catch (\Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'Database connection failed') !== false || stripos($message, 'SQLSTATE') !== false) {
        $message = 'Database connection failed. Check .env DB credentials. Open /api/health.php for details.';
    }
    Http::json(['ok' => false, 'error' => $message], 500);
}
