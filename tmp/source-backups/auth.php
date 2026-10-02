<?php

declare(strict_types=1);

namespace CommitteeManager;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['role'])) {
            return null;
        }

        return [
            'role' => (string) $_SESSION['role'],
            'memberId' => isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
        ];
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            Http::json(['ok' => false, 'error' => 'Authentication required'], 401);
        }

        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            Http::json(['ok' => false, 'error' => 'Admin access required'], 403);
        }

        return $user;
    }

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
        unset($_SESSION['member_id']);

        return true;
    }

    public static function loginMember(int $memberId, string $pin, DataRepository $repo): bool
    {
        if (!$repo->verifyMemberPin($memberId, $pin)) {
            return false;
        }

        $_SESSION['role'] = 'user';
        $_SESSION['member_id'] = $memberId;

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
