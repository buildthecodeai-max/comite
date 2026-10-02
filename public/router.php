<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Prefer public/api wrappers (works on cPanel). Fall back to root api/ for local.
if (preg_match('#^/api/(auth|data|public|health)\.php#', $uri, $matches)) {
    $name = $matches[1];
    $publicApi = __DIR__ . '/api/' . $name . '.php';
    if (is_file($publicApi)) {
        require $publicApi;
        return true;
    }
    require dirname(__DIR__) . '/api/' . $name . '.php';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
