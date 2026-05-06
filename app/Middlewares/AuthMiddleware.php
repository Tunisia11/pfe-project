<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\AuthService;
use App\Services\ResponseHelper;
use Base;

final class AuthMiddleware
{
    public static function requireAuth(Base $f3, AuthService $authService, bool $redirectToLogin = false): bool
    {
        $user = $authService->currentUser();
        if ($user !== null) {
            $f3->set('admin.user', $user);
            return true;
        }

        if ($redirectToLogin) {
            $target = rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/gui'));
            header('Location: /login?next=' . $target, true, 302);
            return false;
        }

        ResponseHelper::error($f3, 'Authentication required', 401);
        return false;
    }
}
