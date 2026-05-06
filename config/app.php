<?php

declare(strict_types=1);

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"");

            if ($key === '') {
                continue;
            }

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

loadEnvFile(__DIR__ . '/../.env');

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Tunis');

return [
    'name' => $_ENV['APP_NAME'] ?? 'Piler Archive Extractor API',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? 'true', FILTER_VALIDATE_BOOL),
    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Africa/Tunis',
    'log_path' => __DIR__ . '/../logs/app.log',
    'app_db' => [
        'driver' => $_ENV['APP_DB_DRIVER'] ?? 'sqlite',
        'path' => $_ENV['APP_DB_PATH'] ?? 'storage/database/app.sqlite',
    ],
    'admin' => [
        'email' => $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com',
        'password' => $_ENV['ADMIN_PASSWORD'] ?? 'change-me-now',
    ],
    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'piler_admin_session',
        'lifetime_minutes' => max(5, (int) ($_ENV['SESSION_LIFETIME_MINUTES'] ?? 120)),
    ],
    'ai' => [
        'enabled' => filter_var($_ENV['AI_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL),
        'provider' => $_ENV['AI_PROVIDER'] ?? 'mock',
        'batch_size' => max(1, (int) ($_ENV['AI_BATCH_SIZE'] ?? 50)),
    ],
];
