<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AppSettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM app_settings WHERE "key" = :key LIMIT 1');
        $statement->execute([':key' => $key]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function set(string $key, ?string $value, string $type = 'string'): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings ("key", value, type, updated_at)
             VALUES (:key, :value, :type, :updated_at)
             ON CONFLICT("key") DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':key' => $key,
            ':value' => $value,
            ':type' => $type,
            ':updated_at' => gmdate('c'),
        ]);
    }
}
