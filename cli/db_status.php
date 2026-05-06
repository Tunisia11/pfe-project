<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\AppDatabase;
use App\Database\MigrationRunner;

$appConfig = require __DIR__ . '/../config/app.php';
$database = new AppDatabase($appConfig['app_db'] ?? []);
$pdo = $database->pdo();
$runner = new MigrationRunner($pdo, __DIR__ . '/../storage/migrations');

$count = static function (string $table) use ($pdo): int {
    return (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();
};

printf("Database path: %s\n", $database->path());
printf("Migrations applied: %d\n", $runner->appliedCount());
printf("Admin users: %d\n", $count('admin_users'));
printf("Extracted contacts: %d\n", $count('extracted_contacts'));
printf("Sync runs: %d\n", $count('sync_runs'));
