<?php

declare(strict_types=1);

/**
 * Ultimate cPanel fix for API 500 errors:
 * - Runs entirely inside the public document root
 * - Loads .env without putenv() (often disabled on shared hosting)
 * - Returns JSON diagnostics instead of a blank 500
 */

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__); // project root (parent of public/)
$report = [
    'ok' => false,
    'php_version' => PHP_VERSION,
    'pwd' => getcwd(),
    'script' => __FILE__,
    'project_root' => $root,
    'checks' => [],
];

function check(array &$report, string $name, bool $ok, string $detail = ''): void
{
    $report['checks'][$name] = ['ok' => $ok, 'detail' => $detail];
}

check($report, 'php81', version_compare(PHP_VERSION, '8.1.0', '>='), 'Need PHP 8.1+. Current: ' . PHP_VERSION);
check($report, 'pdo', class_exists('PDO'), 'PDO extension');
check($report, 'pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'pdo_mysql OK' : 'Enable pdo_mysql in MultiPHP INI Editor');

$envFile = $root . '/.env';
$envReadable = is_file($envFile) && is_readable($envFile);
check($report, 'env_file', $envReadable, $envReadable ? $envFile : 'Missing readable .env at project root (sibling of public/)');

$env = [];
if ($envReadable) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = trim($v, "\"'");
    }
}

$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';

check(
    $report,
    'env_db',
    $name !== '' && $user !== '' && $name !== 'your_cpanel_db_name',
    'DB_HOST=' . $host . '; DB_NAME=' . ($name !== '' ? $name : '(empty)') . '; DB_USER=' . ($user !== '' ? $user : '(empty)') . '; DB_PASS=' . ($pass !== '' ? '(set)' : '(empty)')
);

$srcOk = is_file($root . '/src/Database.php');
$apiOk = is_file($root . '/api/auth.php');
$tplOk = is_file($root . '/templates/app.html');
check($report, 'src_files', $srcOk, $srcOk ? 'src/Database.php found' : 'src/ missing above public/');
check($report, 'api_files', $apiOk, $apiOk ? 'api/auth.php found' : 'api/ missing above public/');
check($report, 'template', $tplOk, $tplOk ? 'templates/app.html found' : 'templates/ missing above public/');

if (!$name || !$user || !extension_loaded('pdo_mysql')) {
    $report['hint'] = 'Fix .env DB_* values and enable pdo_mysql, then reload.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    check($report, 'db_connect', true, 'Connected');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check($report, 'tables', count($tables) >= 4, 'Tables: ' . implode(', ', $tables));

    $settings = $pdo->query('SELECT admin_user, LENGTH(admin_pass_hash) AS hash_len FROM settings WHERE id = 1')->fetch();
    if ($settings) {
        check($report, 'settings', true, 'admin_user=' . $settings['admin_user'] . '; hash_len=' . $settings['hash_len']);
    } else {
        check($report, 'settings', false, 'No settings row — import sql/committee_manager.sql');
    }

    $members = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    check($report, 'members', true, $members . ' members');

    $report['ok'] = true;
    foreach ($report['checks'] as $c) {
        if (!$c['ok']) {
            $report['ok'] = false;
            break;
        }
    }
    $report['login'] = 'Try admin / admin123 after all checks are ok';
} catch (Throwable $e) {
    check($report, 'db_connect', false, $e->getMessage());
    $report['ok'] = false;
    $report['hint'] = 'Update .env with exact cPanel MySQL database name, username, and password. Host is usually localhost.';
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
