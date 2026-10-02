<?php

declare(strict_types=1);

$template = dirname(__DIR__) . '/templates/app.html';
if (!is_file($template)) {
    http_response_code(500);
    echo 'Application template missing.';
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$env = static fn (string $key): string => (string) ($_ENV[$key] ?? (getenv($key) ?: ''));
$configScript = '<script>window.OAUTH_CONFIG=' . json_encode([
    'googleClientId' => $env('GOOGLE_CLIENT_ID'),
    'facebookAppId'  => $env('FACEBOOK_APP_ID'),
], JSON_HEX_TAG) . ';</script>';

$html = file_get_contents($template);
$pos = strpos($html, '</head>');
if ($pos !== false) {
    $html = substr($html, 0, $pos) . $configScript . "\n" . substr($html, $pos);
}
echo $html;
