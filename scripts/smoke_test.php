<?php

declare(strict_types=1);

/**
 * Smoke test — run after seed.php with MySQL available:
 *   php scripts/smoke_test.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Auth;
use CommitteeManager\DataRepository;
use CommitteeManager\Seeder;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "OK: $message\n";
}

try {
    Seeder::run();
    $repo = new DataRepository();

    $data = $repo->loadAll();
    assertTrue($data['committeeName'] === 'Hashmat Commite', 'loads settings');
    assertTrue(count($data['members']) === 0, 'fresh install has no members');
    assertTrue(count($data['trashedMembers']) === 0, 'fresh install has empty trash');
    assertTrue($data['adminPass'] === '', 'does not expose admin password');

    assertTrue(Auth::loginAdmin('admin', 'admin123', $repo), 'admin login');
    assertTrue(Auth::isAdmin(), 'admin session active');

    $data['members'][] = [
        'id' => 1,
        'name' => 'Test Member',
        'shares' => 2,
        'phone' => '03001234567',
        'email' => '',
        'pin' => '1',
        'prefMonth' => 0,
        'payments' => ['M1' => true],
    ];
    $data['nextId'] = 2;
    $saved = $repo->saveAll($data);
    assertTrue(count($saved['members']) === 1, 'admin save persists members');
    assertTrue($saved['members'][0]['payments']['M1'] === true, 'admin save persists payments');

    $trashData = $saved;
    $member = $trashData['members'][0];
    unset($member['payments']);
    $trashData['trashedMembers'] = [[
        ...$member,
        'payments' => $saved['members'][0]['payments'],
        'deletedAt' => (int) (microtime(true) * 1000),
        'deletedBy' => 'Admin',
    ]];
    $trashData['members'] = [];
    $trashed = $repo->saveAll($trashData);
    assertTrue(count($trashed['members']) === 0, 'soft delete removes from active list');
    assertTrue(count($trashed['trashedMembers']) === 1, 'soft delete moves member to trash');

    $permanent = $repo->permanentDeleteMembers([1]);
    assertTrue(count($permanent['trashedMembers']) === 0, 'permanent delete removes from trash');

    Auth::logout();
    assertTrue(Auth::loginAdmin('admin', 'admin123', $repo), 'admin re-login');

    $reset = $repo->resetToDefaults();
    assertTrue($reset['committeeName'] === 'Hashmat Commite', 'reset restores defaults');
    assertTrue(count($reset['members']) === 0, 'reset clears members');

    echo "\nAll smoke tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
