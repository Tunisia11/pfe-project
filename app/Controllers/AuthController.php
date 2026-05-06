<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ResponseHelper;
use Base;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly string $projectRoot
    ) {
    }

    public function loginPage(Base $f3): void
    {
        if ($this->authService->currentUser() !== null) {
            header('Location: /gui', true, 302);
            return;
        }

        $file = $this->projectRoot . '/public/login.html';
        if (!is_file($file)) {
            ResponseHelper::error($f3, 'Login page not found', 404);
            return;
        }

        $html = file_get_contents($file);
        if ($html === false) {
            ResponseHelper::error($f3, 'Login page could not be loaded', 500);
            return;
        }

        $html = str_replace('{{csrf_token}}', htmlspecialchars($this->authService->csrfToken(), ENT_QUOTES, 'UTF-8'), $html);
        $f3->set('HEADERS.Content-Type', 'text/html; charset=utf-8');
        echo $html;
    }

    public function login(Base $f3): void
    {
        if ($this->authService->isLoginLocked()) {
            ResponseHelper::error($f3, 'Too many failed login attempts. Try again in a few minutes.', 429);
            return;
        }

        $payload = $this->payload();
        $csrfToken = (string) ($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!$this->authService->verifyCsrf($csrfToken)) {
            ResponseHelper::error($f3, 'Invalid security token. Refresh the login page and try again.', 419);
            return;
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $password === '') {
            ResponseHelper::error($f3, 'Invalid email or password.', 422);
            return;
        }

        $user = $this->authService->login($email, $password, $this->ipAddress(), $this->userAgent());
        if ($user === null) {
            ResponseHelper::error($f3, 'Invalid email or password.', 401);
            return;
        }

        ResponseHelper::success($f3, [
            'data' => [
                'user' => $user,
                'csrf_token' => $this->authService->csrfToken(),
            ],
        ]);
    }

    public function logout(Base $f3): void
    {
        $payload = $this->payload();
        $csrfToken = (string) ($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!$this->authService->verifyCsrf($csrfToken)) {
            ResponseHelper::error($f3, 'Invalid security token.', 419);
            return;
        }

        $this->authService->logout(true, $this->ipAddress(), $this->userAgent());
        ResponseHelper::success($f3, ['message' => 'Logged out.']);
    }

    public function me(Base $f3): void
    {
        $user = $this->authService->currentUser();
        ResponseHelper::success($f3, [
            'data' => [
                'authenticated' => $user !== null,
                'user' => $user,
                'csrf_token' => $this->authService->csrfToken(),
            ],
        ]);
    }

    private function payload(): array
    {
        $raw = file_get_contents('php://input');
        $data = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return array_merge($_POST, $data);
    }

    private function ipAddress(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($agent) ? substr($agent, 0, 500) : null;
    }
}
