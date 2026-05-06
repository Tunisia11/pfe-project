<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\AppDatabase;
use App\Database\MigrationRunner;

$appConfig = require __DIR__ . '/../config/app.php';
$database = new AppDatabase($appConfig['app_db'] ?? []);
$runner = new MigrationRunner($database->pdo(), __DIR__ . '/../storage/migrations');
$applied = $runner->run();

printf("App database: %s\n", $database->path());
if ($applied === []) {
    echo "No pending migrations.\n";
    exit(0);
}

echo "Applied migrations:\n";
foreach ($applied as $migration) {
    printf("- %s\n", $migration);
}
