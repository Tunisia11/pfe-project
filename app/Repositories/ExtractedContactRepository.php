<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ExtractedContactRepository
{
    private const ALLOWED_STATUSES = ['pending', 'approved', 'ignored', 'blocked'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsert(string $email, ?string $category, int $sourceCount, ?string $firstSeenAt = null, ?string $lastSeenAt = null): array
    {
        $email = mb_strtolower(trim($email));
        $domain = explode('@', $email)[1] ?? null;
        $now = gmdate('c');
        $firstSeenAt = $firstSeenAt ?: $now;
        $lastSeenAt = $lastSeenAt ?: $now;
        $existing = $this->findByEmail($email);

        $statement = $this->pdo->prepare(
            'INSERT INTO extracted_contacts
                (email, domain, category, source_count, status, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES
                (:email, :domain, :category, :source_count, :status, :first_seen_at, :last_seen_at, :created_at, :updated_at)
             ON CONFLICT(email) DO UPDATE SET
                domain = excluded.domain,
                category = excluded.category,
                source_count = excluded.source_count,
                status = CASE
                    WHEN extracted_contacts.status IN (\'approved\', \'ignored\', \'blocked\')
                    THEN extracted_contacts.status
                    ELSE excluded.status
                END,
                first_seen_at = COALESCE(extracted_contacts.first_seen_at, excluded.first_seen_at),
                last_seen_at = excluded.last_seen_at,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':email' => $email,
            ':domain' => $domain,
            ':category' => $category,
            ':source_count' => max(0, $sourceCount),
            ':status' => 'pending',
            ':first_seen_at' => $firstSeenAt,
            ':last_seen_at' => $lastSeenAt,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return [
            'contact' => $this->findByEmail($email) ?: [],
            'created' => $existing === null,
            'updated' => $existing !== null,
        ];
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM extracted_contacts WHERE email = :email LIMIT 1');
        $statement->execute([':email' => mb_strtolower(trim($email))]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateStatus(int $id, string $status, ?string $notes = null): ?array
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'UPDATE extracted_contacts SET status = :status, notes = :notes, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':status' => $status,
            ':notes' => $notes,
            ':updated_at' => gmdate('c'),
        ]);

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM extracted_contacts WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function list(?string $status = null, ?string $query = null, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);
        [$where, $params] = $this->where($status, $query);

        $statement = $this->pdo->prepare(
            'SELECT * FROM extracted_contacts' . $where . ' ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countFiltered(?string $status = null, ?string $query = null): int
    {
        [$where, $params] = $this->where($status, $query);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM extracted_contacts' . $where);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function exportRows(string $status, ?string $query, bool $includeBlocked): array
    {
        $conditions = [];
        $params = [];

        if ($status === 'all') {
            if (!$includeBlocked) {
                $conditions[] = 'status != :blocked_status';
                $params[':blocked_status'] = 'blocked';
            }
        } elseif ($status !== '') {
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                return [];
            }

            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        if ($query !== null && trim($query) !== '') {
            $conditions[] = '(email LIKE :query OR domain LIKE :query OR category LIKE :query)';
            $params[':query'] = '%' . trim($query) . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            'SELECT * FROM extracted_contacts' . $where . ' ORDER BY email ASC'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public function stats(): array
    {
        $stats = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'ignored' => 0,
            'blocked' => 0,
            'domains' => 0,
            'domains_count' => 0,
        ];

        foreach ($this->pdo->query('SELECT status, COUNT(*) AS count FROM extracted_contacts GROUP BY status')->fetchAll() as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['count'];
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
        }

        $domains = (int) $this->pdo
            ->query('SELECT COUNT(DISTINCT domain) FROM extracted_contacts WHERE domain IS NOT NULL AND domain != ""')
            ->fetchColumn();
        $stats['domains'] = $domains;
        $stats['domains_count'] = $domains;

        return $stats;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM extracted_contacts')->fetchColumn();
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function where(?string $status, ?string $query): array
    {
        $conditions = [];
        $params = [];

        if ($status !== null && $status !== '' && $status !== 'all') {
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                return [' WHERE 1 = 0', []];
            }

            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        if ($query !== null && trim($query) !== '') {
            $conditions[] = '(email LIKE :query OR domain LIKE :query OR category LIKE :query)';
            $params[':query'] = '%' . trim($query) . '%';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
