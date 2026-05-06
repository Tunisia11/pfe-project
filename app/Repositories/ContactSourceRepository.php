<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ContactSourceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertIfMissing(int $contactId, int $emailArchiveId, string $sourceField): bool
    {
        if ($contactId <= 0 || $emailArchiveId <= 0 || $sourceField === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO contact_sources (contact_id, email_archive_id, source_field, created_at)
             VALUES (:contact_id, :email_archive_id, :source_field, :created_at)'
        );
        $statement->execute([
            ':contact_id' => $contactId,
            ':email_archive_id' => $emailArchiveId,
            ':source_field' => $sourceField,
            ':created_at' => gmdate('c'),
        ]);

        return $statement->rowCount() > 0;
    }

    public function listByContactId(int $contactId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);
        $statement = $this->pdo->prepare(
            'SELECT email_archive_id, source_field, created_at
             FROM contact_sources
             WHERE contact_id = :contact_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
