<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

final class Seeder
{
    public static function run(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();

        $pdo->exec('DELETE FROM password_reset_tokens');
        $pdo->exec('DELETE FROM winners');
        $pdo->exec('DELETE FROM payments');
        $pdo->exec('DELETE FROM members');
        $pdo->exec('DELETE FROM settings');

        $stmt = $pdo->prepare(
            'INSERT INTO settings (
                id, committee_name, committee_subtitle, admin_user, admin_pass_hash, admin_email,
                recovery_contact, recovery_note, amt_per_share, total_months, prize_per_share,
                start_month, current_month, next_id, updated_at
            ) VALUES (
                1, :committee_name, :committee_subtitle, :admin_user, :admin_pass_hash, :admin_email,
                :recovery_contact, :recovery_note, :amt_per_share, :total_months, :prize_per_share,
                :start_month, :current_month, :next_id, :updated_at
            )'
        );

        $stmt->execute([
            'committee_name' => 'Hashmat Commite',
            'committee_subtitle' => 'Committee Management System',
            'admin_user' => 'admin',
            'admin_pass_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'admin_email' => '',
            'recovery_contact' => '',
            'recovery_note' => 'Contact the committee admin to reset your password or PIN.',
            'amt_per_share' => 2000,
            'total_months' => 25,
            'prize_per_share' => 50000,
            'start_month' => '',
            'current_month' => 1,
            'next_id' => 1,
            'updated_at' => (int) (microtime(true) * 1000),
        ]);

    }
}
