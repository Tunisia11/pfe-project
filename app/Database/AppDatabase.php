<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class AppDatabase
{
    private ?PDO $pdo = null;

    /**
     * @param array{driver?: string, path?: string} $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = $this->driver();
        if ($driver !== 'sqlite') {
            throw new RuntimeException(sprintf('Unsupported app database driver: %s', $driver));
        }

        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create app database directory: %s', $directory));
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        return $this->pdo;
    }

    public function driver(): string
    {
        return strtolower((string) ($this->config['driver'] ?? $_ENV['APP_DB_DRIVER'] ?? 'sqlite'));
    }

    public function path(): string
    {
        $path = (string) ($this->config['path'] ?? $_ENV['APP_DB_PATH'] ?? 'storage/database/app.sqlite');
        if ($path === '') {
            $path = 'storage/database/app.sqlite';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectRoot() . '/' . ltrim($path, '/');
    }

    private function projectRoot(): string
    {
        return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }
}
