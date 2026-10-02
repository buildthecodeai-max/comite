<?php

declare(strict_types=1);

/**
 * cPanel diagnostic endpoint — open /api/health.php in the browser.
 * Remove or protect this file after the site is working.
 */

header('Content-Type: application/json; charset=utf-8');

$report = [
    'ok' => false,
    'php_version' => PHP_VERSION,
    'checks' => [],
];

function addCheck(array &$report, string $name, bool $ok, string $detail = ''): void
{
    $report['checks'][$name] = [
        'ok' => $ok,
        'detail' => $detail,
    ];
}

addCheck($report, 'php_version', version_compare(PHP_VERSION, '8.1.0', '>='), 'Need PHP 8.1+. Current: ' . PHP_VERSION);
addCheck($report, 'pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'pdo_mysql loaded' : 'Enable pdo_mysql in MultiPHP INI Editor');
addCheck($report, 'json', extension_loaded('json'), 'json extension');
addCheck($report, 'session', extension_loaded('session'), 'session extension');

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$envExists = is_file($envPath);
addCheck($report, 'env_file', $envExists, $envExists ? 'Found .env at project root' : 'Missing .env — copy .env.example to .env and set DB credentials');

if ($envExists) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$dbHost = getenv('DB_HOST') ?: '(not set)';
$dbName = getenv('DB_NAME') ?: '(not set)';
$dbUser = getenv('DB_USER') ?: '(not set)';
$dbPassSet = getenv('DB_PASS') !== false && getenv('DB_PASS') !== '';

addCheck(
    $report,
    'env_values',
    $dbName !== '(not set)' && $dbUser !== '(not set)' && $dbName !== 'your_cpanel_db_name',
    "DB_HOST={$dbHost}; DB_NAME={$dbName}; DB_USER={$dbUser}; DB_PASS=" . ($dbPassSet ? '(set)' : '(empty)')
);

try {
    require_once $root . '/src/bootstrap.php';
    addCheck($report, 'bootstrap', true, 'bootstrap.php loaded');
} catch (Throwable $e) {
    addCheck($report, 'bootstrap', false, $e->getMessage());
    $report['hint'] = 'Fix bootstrap/.env first, then reload this page.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = \CommitteeManager\Database::connection();
    addCheck($report, 'database_connection', true, 'Connected to MySQL');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    addCheck($report, 'tables', count($tables) > 0, 'Tables: ' . implode(', ', $tables));

    $settings = $pdo->query('SELECT id, admin_user, LENGTH(admin_pass_hash) AS hash_len FROM settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        addCheck(
            $report,
            'admin_settings',
            !empty($settings['admin_user']) && (int) $settings['hash_len'] > 20,
            'admin_user=' . $settings['admin_user'] . '; password hash length=' . $settings['hash_len']
        );
    } else {
        addCheck($report, 'admin_settings', false, 'No settings row. Import sql/committee_manager.sql or run php scripts/seed.php');
    }

    $memberCount = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    addCheck($report, 'members', $memberCount >= 0, $memberCount . ' member row(s)');

    $report['ok'] = true;
    foreach ($report['checks'] as $check) {
        if (!$check['ok']) {
            $report['ok'] = false;
            break;
        }
    }
} catch (Throwable $e) {
    addCheck($report, 'database_connection', false, $e->getMessage());
    $report['ok'] = false;
    $report['hint'] = 'Update .env with your exact cPanel MySQL database name, username, and password. Host is usually localhost.';
}

$report['document_root_hint'] = 'Domain document root should be the public/ folder. API files live one level above public/.';
$report['next_steps'] = [
    'Open this URL: /api/health.php',
    'Fix any check marked ok:false',
    'Try admin login again (admin / admin123 if you imported the provided SQL dump)',
    'Delete or restrict api/health.php after setup',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
