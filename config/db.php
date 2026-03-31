<?php

declare(strict_types=1);

$f3 = Base::instance();

$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'name' => $_ENV['DB_NAME'] ?? 'piler_archive',
    'user' => $_ENV['DB_USER'] ?? 'root',
    'pass' => $_ENV['DB_PASS'] ?? '',
];

$f3->set('db.config', $dbConfig);
$f3->set('db.pdo', null);

$useRealDb = filter_var($_ENV['USE_REAL_DB'] ?? 'false', FILTER_VALIDATE_BOOL);
if ($useRealDb === false) {
    return;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $f3->set('db.pdo', $pdo);
} catch (Throwable $exception) {
    error_log('[DB] PDO connection failed: ' . $exception->getMessage());
}
