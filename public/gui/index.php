<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\AppDatabase;
use App\Repositories\AdminSessionRepository;
use App\Repositories\AdminUserRepository;
use App\Services\AuditLogService;
use App\Services\AuthService;

$appConfig = require __DIR__ . '/../../config/app.php';
$database = new AppDatabase($appConfig['app_db'] ?? []);
$pdo = $database->pdo();
$sessionConfig = $appConfig['session'] ?? [];

$authService = new AuthService(
    new AdminUserRepository($pdo),
    new AdminSessionRepository($pdo),
    new AuditLogService($pdo),
    (string) ($sessionConfig['name'] ?? 'piler_admin_session'),
    (int) ($sessionConfig['lifetime_minutes'] ?? 120)
);

if ($authService->currentUser() === null) {
    header('Location: /login?next=%2Fgui', true, 302);
    exit;
}

$view = __DIR__ . '/../../app/Views/gui.html';
if (!is_file($view)) {
    http_response_code(404);
    echo 'GUI view not found.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($view);
