<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminSessionRepository;
use App\Repositories\AdminUserRepository;

final class AuthService
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminSessionRepository $sessions,
        private readonly AuditLogService $auditLog,
        private readonly string $sessionName,
        private readonly int $sessionLifetimeMinutes
    ) {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => $this->sessionLifetimeMinutes * 60,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function currentUser(): ?array
    {
        $this->startSession();
        $token = (string) ($_SESSION['admin_session_token'] ?? '');
        $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
        if ($token === '' || $userId <= 0) {
            return null;
        }

        $session = $this->sessions->findActiveByTokenHash($this->hashToken($token));
        if ($session === null || (int) $session['user_id'] !== $userId || (int) $session['is_active'] !== 1) {
            $this->logout(false);
            return null;
        }

        return [
            'id' => (int) $session['user_id'],
            'email' => (string) $session['email'],
            'name' => $session['name'] !== null ? (string) $session['name'] : null,
            'role' => (string) $session['role'],
        ];
    }

    public function login(string $email, string $password, ?string $ipAddress, ?string $userAgent): ?array
    {
        $this->startSession();
        if ($this->isLoginLocked()) {
            return null;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailedLogin();
            $this->auditLog->log('admin_login_failed', null, 'admin_user', mb_strtolower(trim($email)), [], $ipAddress, $userAgent);
            return null;
        }

        session_regenerate_id(true);
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + ($this->sessionLifetimeMinutes * 60));
        $this->sessions->create((int) $user['id'], $this->hashToken($token), $ipAddress, $userAgent, $expiresAt);
        $this->users->updateLastLogin((int) $user['id']);

        $_SESSION['admin_user_id'] = (int) $user['id'];
        $_SESSION['admin_session_token'] = $token;
        $_SESSION['failed_login_count'] = 0;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->auditLog->log('admin_login_success', (int) $user['id'], 'admin_user', (string) $user['id'], [], $ipAddress, $userAgent);

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => $user['name'] !== null ? (string) $user['name'] : null,
            'role' => (string) $user['role'],
        ];
    }

    public function logout(bool $logEvent = true, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->startSession();
        $token = (string) ($_SESSION['admin_session_token'] ?? '');
        $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
        if ($token !== '') {
            $this->sessions->revokeByTokenHash($this->hashToken($token));
        }

        if ($logEvent && $userId > 0) {
            $this->auditLog->log('admin_logout', $userId, 'admin_user', (string) $userId, [], $ipAddress, $userAgent);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public function csrfToken(): string
    {
        $this->startSession();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): bool
    {
        $this->startSession();
        $expected = (string) ($_SESSION['csrf_token'] ?? '');

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public function isLoginLocked(): bool
    {
        $this->startSession();
        $count = (int) ($_SESSION['failed_login_count'] ?? 0);
        $lastAttempt = (int) ($_SESSION['failed_login_last_at'] ?? 0);

        return $count >= 5 && $lastAttempt > 0 && (time() - $lastAttempt) < 300;
    }

    private function recordFailedLogin(): void
    {
        $_SESSION['failed_login_count'] = (int) ($_SESSION['failed_login_count'] ?? 0) + 1;
        $_SESSION['failed_login_last_at'] = time();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
