<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\AppDatabase;
use App\Repositories\AdminUserRepository;

$appConfig = require __DIR__ . '/../config/app.php';
$email = trim((string) ($appConfig['admin']['email'] ?? ''));
$password = (string) ($appConfig['admin']['password'] ?? '');

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "ADMIN_EMAIL must be a valid email address.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "ADMIN_PASSWORD must be at least 8 characters.\n");
    exit(1);
}

$database = new AppDatabase($appConfig['app_db'] ?? []);
$users = new AdminUserRepository($database->pdo());
$existing = $users->findByEmail($email);
$users->createOrUpdateFromSeed($email, password_hash($password, PASSWORD_DEFAULT), 'Administrator');

printf("%s admin user: %s\n", $existing === null ? 'Created' : 'Updated', $email);
echo "Password was read from environment and was not printed.\n";
