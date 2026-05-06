<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SyncRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $data): int
    {
        $payloadJson = null;
        if (isset($data['payload']) && is_array($data['payload'])) {
            $payloadJson = json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO sync_runs
                (type, status, emails_processed, total_extracted_addresses, valid_contacts, duplicates_removed,
                 ignored_invalid_or_system_addresses, unique_contacts, message, payload_json, created_at)
             VALUES
                (:type, :status, :emails_processed, :total_extracted_addresses, :valid_contacts, :duplicates_removed,
                 :ignored_invalid_or_system_addresses, :unique_contacts, :message, :payload_json, :created_at)'
        );
        $statement->execute([
            ':type' => (string) ($data['type'] ?? 'contact_extract'),
            ':status' => (string) ($data['status'] ?? 'success'),
            ':emails_processed' => (int) ($data['emails_processed'] ?? 0),
            ':total_extracted_addresses' => (int) ($data['total_extracted_addresses'] ?? 0),
            ':valid_contacts' => (int) ($data['valid_contacts'] ?? 0),
            ':duplicates_removed' => (int) ($data['duplicates_removed'] ?? 0),
            ':ignored_invalid_or_system_addresses' => (int) ($data['ignored_invalid_or_system_addresses'] ?? 0),
            ':unique_contacts' => (int) ($data['unique_contacts'] ?? 0),
            ':message' => $data['message'] ?? null,
            ':payload_json' => $payloadJson,
            ':created_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sync_runs')->fetchColumn();
    }

    public function recent(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $this->pdo->prepare(
            'SELECT id, type, status, emails_processed, total_extracted_addresses, valid_contacts,
                    duplicates_removed, ignored_invalid_or_system_addresses, unique_contacts,
                    message, created_at
             FROM sync_runs
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
