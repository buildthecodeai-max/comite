<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Auth;
use CommitteeManager\DataRepository;
use CommitteeManager\Http;
use CommitteeManager\MultiCommitteeRepository;
use CommitteeManager\PasswordResetService;

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $repo = new DataRepository();
    $enterprise = null;
    try { $enterprise = new MultiCommitteeRepository(null, $repo); $enterprise->defaultCommitteeId(); } catch (\Throwable $ignored) {}
    $action = Http::action();

    if (Http::method() === 'GET' && $action === 'session') {
        $user = Auth::user();
        if ($user === null) {
            Http::json(['loggedIn' => false]);
        }

        Http::json([
            'loggedIn' => true,
            'user' => [
                'role'     => $user['role'],
                'memberId' => $user['memberId'],
                'userId'   => $user['userId'],
            ],
        ]);
    }

    if (Http::method() !== 'POST') {
        Http::json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $body = Http::readJsonBody();

    switch ($action) {
        case 'login':
            $role = (string) ($body['role'] ?? '');
            if ($role === 'admin') {
                $username = trim((string) ($body['username'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                if ($repo->getAdminUsername() === null) {
                    Http::json(['ok' => false, 'error' => 'System not initialized. Import sql/committee_manager.sql or run php scripts/seed.php'], 503);
                }
                if (!Auth::loginAdmin($username, $password, $repo)) {
                    Http::json(['ok' => false, 'error' => 'Invalid admin credentials'], 401);
                }
                Http::json([
                    'ok' => true,
                    'user' => ['role' => 'admin', 'memberId' => null, 'userId' => null],
                ]);
            }

            if ($role === 'owner') {
                $email    = trim((string) ($body['email'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                if ($email === '' || $password === '') {
                    Http::json(['ok' => false, 'error' => 'Email and password are required'], 400);
                }
                $pdo = \CommitteeManager\Database::connection();
                if (!Auth::loginOwner($email, $password, $pdo)) {
                    Http::json(['ok' => false, 'error' => 'Invalid email or password'], 401);
                }
                $user = Auth::user();
                Http::json([
                    'ok' => true,
                    'user' => ['role' => $user['role'], 'memberId' => null, 'userId' => $user['userId']],
                ]);
            }

            if ($role === 'user') {
                $name = trim((string) ($body['name'] ?? ''));
                $pin = trim((string) ($body['pin'] ?? ''));
                $memberId = (int) ($body['memberId'] ?? 0);

                // Prefer private name+PIN login. memberId is accepted only as a legacy fallback
                // after the server resolves the name itself.
                if ($name !== '') {
                    $resolved = $repo->findActiveMemberIdByName($name);
                    if ($resolved === null) {
                        Http::json(['ok' => false, 'error' => 'Name or PIN is incorrect'], 401);
                    }
                    $memberId = $resolved;
                }

                if ($memberId <= 0 || $pin === '') {
                    Http::json(['ok' => false, 'error' => 'Name and PIN are required'], 400);
                }
                if (!Auth::loginMember($memberId, $pin, $repo)) {
                    Http::json(['ok' => false, 'error' => 'Name or PIN is incorrect'], 401);
                }
                Http::json([
                    'ok' => true,
                    'user' => ['role' => 'user', 'memberId' => $memberId, 'userId' => null],
                ]);
            }

            Http::json(['ok' => false, 'error' => 'Invalid role'], 400);

        case 'create-owner':
            $callingUser = Auth::requireSuperAdmin();
            $name     = trim((string) ($body['name'] ?? ''));
            $email    = trim((string) ($body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $owner = $enterprise
                ? $enterprise->createOwner($name, $email, $password, $callingUser)
                : throw new \RuntimeException('Enterprise mode required for owner management.', 503);
            Http::json(['ok' => true, 'owner' => $owner]);

        case 'assign-committee':
            $callingUser  = Auth::requireSuperAdmin();
            $userId       = (int) ($body['userId'] ?? 0);
            $committeeId  = (int) ($body['committeeId'] ?? 0);
            $ownerRole    = (string) ($body['ownerRole'] ?? 'owner');
            if ($userId <= 0 || $committeeId <= 0) {
                Http::json(['ok' => false, 'error' => 'userId and committeeId are required'], 400);
            }
            if ($enterprise) {
                $enterprise->assignOwnerToCommittee($userId, $committeeId, $ownerRole, $callingUser);
            }
            Http::json(['ok' => true]);

        case 'list-owners':
            $callingUser = Auth::requireSuperAdmin();
            $owners = $enterprise ? $enterprise->listOwners($callingUser) : [];
            Http::json(['ok' => true, 'owners' => $owners]);

        case 'signup':
            $name     = trim((string) ($body['name'] ?? ''));
            $email    = trim((string) ($body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $mobile   = trim((string) ($body['mobile'] ?? ''));
            if (!$enterprise) Http::json(['ok' => false, 'error' => 'Database not available'], 503);
            $newUser = $enterprise->signupUser(['name' => $name, 'email' => $email, 'password' => $password, 'mobile' => $mobile]);
            $pdo = \CommitteeManager\Database::connection();
            Auth::loginOwner($email, $password, $pdo);
            Http::json(['ok' => true, 'user' => ['role' => 'app_user', 'memberId' => null, 'userId' => $newUser['id']]]);

        case 'google-login':
        case 'facebook-login':
            $env = static fn (string $key): string => (string) ($_ENV[$key] ?? (getenv($key) ?: ''));
            $fetchJson = static function (string $url, array $headers = []): ?array {
                $ctx = stream_context_create(['http' => [
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers),
                ]]);
                $raw = @file_get_contents($url, false, $ctx);
                $data = $raw === false ? null : json_decode($raw, true);
                return is_array($data) ? $data : null;
            };
            $accessToken = trim((string) ($body['accessToken'] ?? ''));
            if ($accessToken === '') Http::json(['ok' => false, 'error' => 'Access token required'], 400);
            if (!$enterprise) Http::json(['ok' => false, 'error' => 'Database not available'], 503);

            if ($action === 'google-login') {
                $clientId = $env('GOOGLE_CLIENT_ID');
                if ($clientId === '') Http::json(['ok' => false, 'error' => 'Google Sign-In is not configured on this server'], 503);
                $info = $fetchJson('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken));
                if ($info === null) Http::json(['ok' => false, 'error' => 'Could not reach Google to verify sign-in'], 502);
                if (isset($info['error']) || isset($info['error_description'])) Http::json(['ok' => false, 'error' => 'Google sign-in expired or invalid. Please try again.'], 401);
                if (($info['aud'] ?? '') !== $clientId) Http::json(['ok' => false, 'error' => 'Google token was issued for a different app'], 401);
                $profile = $fetchJson('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]) ?? [];
                $providerId = (string) ($info['sub'] ?? '');
                if ($providerId === '' || ($profile['sub'] ?? '') !== $providerId) Http::json(['ok' => false, 'error' => 'Google profile mismatch'], 401);
                if (!filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) Http::json(['ok' => false, 'error' => 'Your Google email address is not verified'], 401);
                $email = (string) ($profile['email'] ?? '');
                $name  = (string) ($profile['name'] ?? '');
            } else {
                $appId     = $env('FACEBOOK_APP_ID');
                $appSecret = $env('FACEBOOK_APP_SECRET');
                if ($appId === '' || $appSecret === '') Http::json(['ok' => false, 'error' => 'Facebook Login is not configured on this server'], 503);
                $debug = $fetchJson('https://graph.facebook.com/debug_token?input_token=' . urlencode($accessToken) . '&access_token=' . urlencode("{$appId}|{$appSecret}"));
                if ($debug === null) Http::json(['ok' => false, 'error' => 'Could not reach Facebook to verify sign-in'], 502);
                $tokenData = $debug['data'] ?? [];
                if (!($tokenData['is_valid'] ?? false) || (string) ($tokenData['app_id'] ?? '') !== $appId) {
                    Http::json(['ok' => false, 'error' => 'Facebook sign-in expired or invalid. Please try again.'], 401);
                }
                $me = $fetchJson('https://graph.facebook.com/me?fields=id,name,email&access_token=' . urlencode($accessToken)
                    . '&appsecret_proof=' . hash_hmac('sha256', $accessToken, $appSecret));
                $providerId = (string) ($me['id'] ?? '');
                if ($providerId === '' || $providerId !== (string) ($tokenData['user_id'] ?? '')) Http::json(['ok' => false, 'error' => 'Facebook profile mismatch'], 401);
                $email = (string) ($me['email'] ?? '');
                $name  = (string) ($me['name'] ?? '');
                if ($email === '') Http::json(['ok' => false, 'error' => 'Your Facebook account has no email address. Please sign up with email instead.'], 422);
            }

            $oauthUser = $enterprise->findOrCreateOAuthUser($action === 'google-login' ? 'google' : 'facebook', $providerId, $email, $name);
            if (($oauthUser['status'] ?? 'active') === 'suspended') Http::json(['ok' => false, 'error' => 'This account is suspended'], 403);
            session_regenerate_id(true);
            $_SESSION['role']    = in_array($oauthUser['role'] ?? '', ['owner', 'app_user'], true) ? $oauthUser['role'] : 'app_user';
            $_SESSION['user_id'] = (int) $oauthUser['id'];
            unset($_SESSION['member_id']);
            Http::json(['ok' => true, 'user' => ['role' => $_SESSION['role'], 'memberId' => null, 'userId' => (int) $oauthUser['id']]]);

        case 'create-user':
            $callingUser = Auth::requireSuperAdmin();
            $name     = trim((string) ($body['name'] ?? ''));
            $email    = trim((string) ($body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $mobile   = trim((string) ($body['mobile'] ?? ''));
            $role     = (string) ($body['role'] ?? 'app_user');
            $plan     = (string) ($body['subscription_plan'] ?? 'free');
            if ($email === '' || $name === '' || $password === '') {
                Http::json(['ok' => false, 'error' => 'Name, email, and password are required'], 400);
            }
            if (!in_array($role, ['owner', 'app_user'], true)) $role = 'app_user';
            if (!$enterprise) Http::json(['ok' => false, 'error' => 'Enterprise mode required'], 503);
            $newUser = $enterprise->signupUser(['name' => $name, 'email' => $email, 'password' => $password, 'mobile' => $mobile]);
            $enterprise->updateUser((int) $newUser['id'], ['role' => $role, 'subscription_plan' => $plan], $callingUser);
            Http::json(['ok' => true, 'user' => $newUser]);

        case 'list-users':
            $callingUser = Auth::requireSuperAdmin();
            $users = $enterprise ? $enterprise->listAllUsers($callingUser) : [];
            Http::json(['ok' => true, 'users' => $users]);

        case 'update-user':
            $callingUser = Auth::requireSuperAdmin();
            $userId = (int) ($body['id'] ?? 0);
            if ($userId <= 0) Http::json(['ok' => false, 'error' => 'User id required'], 400);
            if ($enterprise) $enterprise->updateUser($userId, $body, $callingUser);
            Http::json(['ok' => true]);

        case 'delete-user':
            $callingUser = Auth::requireSuperAdmin();
            $userId = (int) ($body['id'] ?? 0);
            if ($userId <= 0) Http::json(['ok' => false, 'error' => 'User id required'], 400);
            if ($enterprise) $enterprise->deleteUser($userId, $callingUser);
            Http::json(['ok' => true]);

        case 'logout':
            Auth::logout();
            Http::json(['ok' => true]);

        case 'change-admin-pass':
            Auth::requireAdmin();
            $old = (string) ($body['oldPassword'] ?? '');
            $new = (string) ($body['newPassword'] ?? '');
            if (strlen($new) < 4) {
                Http::json(['ok' => false, 'error' => 'Password must be at least 4 characters'], 400);
            }
            if (!$repo->verifyAdminPassword($old)) {
                Http::json(['ok' => false, 'error' => 'Wrong password'], 401);
            }
            $repo->updateAdminPassword($new);
            Http::json(['ok' => true]);

        case 'change-member-pin':
            $user = Auth::requireLogin();
            if ($user['role'] !== 'user' || empty($user['memberId'])) {
                Http::json(['ok' => false, 'error' => 'Member access required'], 403);
            }
            $old = trim((string) ($body['oldPin'] ?? ''));
            $new = trim((string) ($body['newPin'] ?? ''));
            if (strlen($new) !== 4 || !ctype_digit($new)) {
                Http::json(['ok' => false, 'error' => 'PIN must be 4 digits'], 400);
            }
            if (!$repo->verifyMemberPin((int) $user['memberId'], $old)) {
                Http::json(['ok' => false, 'error' => 'Wrong PIN'], 401);
            }
            if ($enterprise) {
                $memberUser = ['role' => 'user', 'memberId' => (int) $user['memberId']];
                $committeeId = $enterprise->resolveCommitteeId(null, $memberUser, true);
                $saved = $enterprise->saveMemberSelf($committeeId, (int) $user['memberId'], [
                    'members' => [['id' => $user['memberId'], 'pin' => $new]],
                    'updatedAt' => (int) (microtime(true) * 1000),
                ], $memberUser);
            } else {
                $current = $repo->loadAll();
                $saved = $repo->saveMemberSelf((int) $user['memberId'], [
                    'members' => [['id' => $user['memberId'], 'pin' => $new]],
                    'updatedAt' => (int) (microtime(true) * 1000),
                ], $current);
            }
            Http::json(['ok' => true, 'data' => $saved]);

        case 'forgot-admin-password':
            $username = trim((string) ($body['username'] ?? ''));
            $email = trim((string) ($body['email'] ?? ''));
            if ($username === '' || $email === '') {
                Http::json(['ok' => false, 'error' => 'Username and email are required'], 400);
            }
            $result = (new PasswordResetService())->requestAdminReset($username, $email, $repo);
            Http::json($result);

        case 'reset-admin-password':
            $username = trim((string) ($body['username'] ?? ''));
            $email = trim((string) ($body['email'] ?? ''));
            $code = trim((string) ($body['code'] ?? ''));
            $newPassword = (string) ($body['newPassword'] ?? '');
            if ($username === '' || $email === '' || $code === '' || $newPassword === '') {
                Http::json(['ok' => false, 'error' => 'All fields are required'], 400);
            }
            (new PasswordResetService())->resetAdminPassword($username, $email, $code, $newPassword, $repo);
            Http::json(['ok' => true, 'message' => 'Password reset successfully. You can log in with your new password.']);

        default:
            Http::json(['ok' => false, 'error' => 'Unknown action'], 404);
    }
} catch (\Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'Database connection failed') !== false || stripos($message, 'SQLSTATE') !== false) {
        $message = 'Database connection failed. Check .env DB_HOST/DB_NAME/DB_USER/DB_PASS on the server. Open /api/health.php for details.';
    }
    Http::json(['ok' => false, 'error' => $message], 500);
}
