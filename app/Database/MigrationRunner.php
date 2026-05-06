<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDirectory
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $files = glob(rtrim($this->migrationDirectory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $newlyApplied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration: %s', $file));
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (:migration, :applied_at)');
                $statement->execute([
                    ':migration' => $name,
                    ':applied_at' => gmdate('c'),
                ]);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    public function appliedCount(): int
    {
        $this->ensureMigrationsTable();

        return (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )'
        );
    }

    /**
     * @return array<string, true>
     */
    private function appliedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll();
        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['migration']] = true;
        }

        return $applied;
    }
}
