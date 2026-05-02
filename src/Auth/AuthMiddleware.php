<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Response;

final class AuthMiddleware
{
    public static function requireAuth(AuthService $auth): void
    {
        if ($auth->check()) {
            return;
        }

        Response::flash('error', 'Faca login para acessar a area principal.');
        Response::redirect('/login.php');
    }

    public static function requireGuest(AuthService $auth): void
    {
        if (!$auth->check()) {
            return;
        }

        Response::redirect('/index.php');
    }
}
