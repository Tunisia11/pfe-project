<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class AuditLogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        string $action,
        ?int $adminUserId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $metadataJson = $metadata === []
            ? null
            : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (admin_user_id, action, entity_type, entity_id, metadata_json, ip_address, user_agent, created_at)
             VALUES
                (:admin_user_id, :action, :entity_type, :entity_id, :metadata_json, :ip_address, :user_agent, :created_at)'
        );
        $statement->execute([
            ':admin_user_id' => $adminUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':metadata_json' => $metadataJson,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':created_at' => gmdate('c'),
        ]);
    }
}
