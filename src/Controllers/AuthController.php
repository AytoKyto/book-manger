<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Response;

final class AuthController
{
    public static function login(): void
    {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $password = (string) ($body['password'] ?? '');

        if (Auth::attempt($password)) {
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'invalid_password'], 401);
        }
    }

    public static function logout(): void
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }

    public static function status(): void
    {
        Response::json(['authenticated' => Auth::isLoggedIn()]);
    }
}
