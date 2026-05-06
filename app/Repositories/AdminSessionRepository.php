<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminSessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, ?string $ipAddress, ?string $userAgent, string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_sessions (user_id, session_token_hash, ip_address, user_agent, expires_at, created_at)
             VALUES (:user_id, :session_token_hash, :ip_address, :user_agent, :expires_at, :created_at)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':session_token_hash' => $tokenHash,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':expires_at' => $expiresAt,
            ':created_at' => gmdate('c'),
        ]);
    }

    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.*, u.email, u.name, u.role, u.is_active
             FROM admin_sessions s
             INNER JOIN admin_users u ON u.id = s.user_id
             WHERE s.session_token_hash = :session_token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > :now
             LIMIT 1'
        );
        $statement->execute([
            ':session_token_hash' => $tokenHash,
            ':now' => gmdate('c'),
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function revokeByTokenHash(string $tokenHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = :revoked_at WHERE session_token_hash = :session_token_hash AND revoked_at IS NULL'
        );
        $statement->execute([
            ':session_token_hash' => $tokenHash,
            ':revoked_at' => gmdate('c'),
        ]);
    }
}
