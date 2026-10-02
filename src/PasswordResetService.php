<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

final class PasswordResetService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function requestAdminReset(string $username, string $email, DataRepository $repo): array
    {
        $settings = $repo->loadAll();
        $adminUser = (string) ($settings['adminUser'] ?? '');
        $adminEmail = strtolower(trim((string) ($settings['adminEmail'] ?? '')));
        $email = strtolower(trim($email));
        $username = trim($username);

        if ($adminEmail === '') {
            throw new \RuntimeException('Admin email is not configured. Ask the system administrator to set it in Email Settings.');
        }

        if ($username !== $adminUser || $email !== $adminEmail) {
            throw new \RuntimeException('Username or email does not match our records.');
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = (int) (microtime(true) * 1000) + (15 * 60 * 1000);

        $this->pdo->exec('DELETE FROM password_reset_tokens WHERE email = ' . $this->pdo->quote($email));

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email, token_hash, expires_at, created_at)
             VALUES (:email, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'email' => $email,
            'token_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $expiresAt,
            'created_at' => (int) (microtime(true) * 1000),
        ]);

        $appName = (string) ($settings['committeeName'] ?? 'KametiPro');
        $sent = Mailer::send(
            $email,
            "$appName — Admin Password Reset Code",
            "Your password reset code is: $code\n\nThis code expires in 15 minutes.\n\nIf you did not request this, ignore this email."
        );

        if (!$sent) {
            throw new \RuntimeException('Unable to send reset email. Check server mail configuration and try again.');
        }

        return [
            'ok' => true,
            'message' => 'A reset code has been sent to your admin email address.',
            'emailSent' => true,
        ];
    }

    public function resetAdminPassword(string $username, string $email, string $code, string $newPassword, DataRepository $repo): void
    {
        if (strlen($newPassword) < 4) {
            throw new \RuntimeException('Password must be at least 4 characters.');
        }

        $settings = $repo->loadAll();
        $adminUser = (string) ($settings['adminUser'] ?? '');
        $adminEmail = strtolower(trim((string) ($settings['adminEmail'] ?? '')));
        $email = strtolower(trim($email));
        $username = trim($username);
        $code = trim($code);

        if ($username !== $adminUser || $email !== $adminEmail) {
            throw new \RuntimeException('Username or email does not match our records.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT token_hash, expires_at FROM password_reset_tokens WHERE email = :email ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new \RuntimeException('No reset request found. Request a new code first.');
        }

        if ((int) $row['expires_at'] < (int) (microtime(true) * 1000)) {
            throw new \RuntimeException('Reset code has expired. Request a new one.');
        }

        if (!password_verify($code, $row['token_hash'])) {
            throw new \RuntimeException('Invalid reset code.');
        }

        $repo->updateAdminPassword($newPassword);
        $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email')->execute(['email' => $email]);
    }
}
