<?php
declare(strict_types=1);

final class Auth
{
    public static function requireAccess(): void
    {
        $password = (string) config('admin_password');
        if ($password !== '') {
            $username = (string) config('admin_username');
            $providedUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
            $providedPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

            if (!hash_equals($username, $providedUser) || !hash_equals($password, $providedPassword)) {
                self::denyAccess();
            }
        }

        self::startSession();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(string $token): bool
    {
        self::startSession();

        return isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && $token !== ''
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $isHttps,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }

    private static function denyAccess(): void
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Gateway Test Console", charset="UTF-8"');
        header('Content-Type: text/plain; charset=utf-8');
        echo '需要管理员身份验证。';
        exit;
    }
}
