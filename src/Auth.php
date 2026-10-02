<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

final class Auth
{
    /** Roles that count as "admin-level" for UI access. */
    private const ADMIN_ROLES = ['admin', 'owner'];

    public static function user(): ?array
    {
        if (empty($_SESSION['role'])) {
            return null;
        }

        return [
            'role'     => (string) $_SESSION['role'],
            'memberId' => isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
            'userId'   => isset($_SESSION['user_id'])   ? (int) $_SESSION['user_id']   : null,
        ];
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    /** True for the global superadmin AND committee owners. */
    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], self::ADMIN_ROLES, true);
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            Http::json(['ok' => false, 'error' => 'Authentication required'], 401);
        }

        return $user;
    }

    /** Accepts both superadmin ('admin') and committee owner ('owner'). */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], self::ADMIN_ROLES, true)) {
            Http::json(['ok' => false, 'error' => 'Admin access required'], 403);
        }

        return $user;
    }

    /** Accepts only the global superadmin. */
    public static function requireSuperAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            Http::json(['ok' => false, 'error' => 'Superadmin access required'], 403);
        }

        return $user;
    }

    // ── Login helpers ──────────────────────────────────────────────────────

    public static function loginAdmin(string $username, string $password, DataRepository $repo): bool
    {
        $adminUser = $repo->getAdminUsername();
        if ($adminUser === null || $username !== $adminUser) {
            return false;
        }

        if (!$repo->verifyAdminPassword($password)) {
            return false;
        }

        $_SESSION['role'] = 'admin';
        unset($_SESSION['member_id'], $_SESSION['user_id']);

        return true;
    }

    public static function loginOwner(string $email, string $password, PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, role, status FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        if (($row['status'] ?? 'active') === 'suspended') {
            return false;
        }

        $_SESSION['role']    = in_array($row['role'], ['owner', 'app_user'], true) ? $row['role'] : 'owner';
        $_SESSION['user_id'] = (int) $row['id'];
        unset($_SESSION['member_id']);

        return true;
    }

    public static function loginMember(int $memberId, string $pin, DataRepository $repo): bool
    {
        if (!$repo->verifyMemberPin($memberId, $pin)) {
            return false;
        }

        $_SESSION['role']      = 'user';
        $_SESSION['member_id'] = $memberId;
        unset($_SESSION['user_id']);

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
