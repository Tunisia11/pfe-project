<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminUserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email LIMIT 1');
        $statement->execute([':email' => mb_strtolower(trim($email))]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createOrUpdateFromSeed(string $email, string $passwordHash, ?string $name = null): array
    {
        $email = mb_strtolower(trim($email));
        $now = gmdate('c');
        $existing = $this->findByEmail($email);

        if ($existing !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE admin_users
                 SET password_hash = :password_hash, name = COALESCE(:name, name), is_active = 1, updated_at = :updated_at
                 WHERE email = :email'
            );
            $statement->execute([
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':name' => $name,
                ':updated_at' => $now,
            ]);

            return $this->findByEmail($email) ?: $existing;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (email, password_hash, name, role, is_active, created_at, updated_at)
             VALUES (:email, :password_hash, :name, :role, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':name' => $name,
            ':role' => 'admin',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->findByEmail($email) ?: [];
    }

    public function updateLastLogin(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id'
        );
        $now = gmdate('c');
        $statement->execute([
            ':id' => $id,
            ':last_login_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }
}
