<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['authenticated']);
    }

    public static function attempt(string $password): bool
    {
        $hash = Config::appPasswordHash();
        if ($hash === null || $hash === '') {
            return false;
        }
        if (password_verify($password, $hash)) {
            self::start();
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            Response::json(['error' => 'unauthenticated'], 401);
            exit;
        }
    }
}
