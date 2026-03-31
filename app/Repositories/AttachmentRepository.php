<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Attachment;
use App\Services\PilerDumpDataSource;
use PDO;

final class AttachmentRepository
{
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?PilerDumpDataSource $dumpDataSource = null
    ) {
    }

    public function findByEmailId(int $emailId): array
    {
        if ($this->pdo !== null) {
            return $this->findByEmailIdFromPiler($emailId);
        }

        if ($this->dumpDataSource !== null) {
            return $this->dumpDataSource->getAttachmentsByEmailId($emailId);
        }

        return array_values(array_filter(
            $this->mockAttachments(),
            static fn (array $attachment): bool => $attachment['email_id'] === $emailId
        ));
    }

    private function mockAttachments(): array
    {
        return array_map(
            static fn (array $row): array => Attachment::fromArray($row)->toArray(),
            [
                [
                    'id' => 101,
                    'email_id' => 1,
                    'filename' => 'kickoff-notes.pdf',
                    'size' => 203948,
                    'type' => 'application/pdf',
                ],
                [
                    'id' => 102,
                    'email_id' => 2,
                    'filename' => 'api-logs.zip',
                    'size' => 509024,
                    'type' => 'application/zip',
                ],
                [
                    'id' => 103,
                    'email_id' => 4,
                    'filename' => 'contacts.csv',
                    'size' => 64230,
                    'type' => 'text/csv',
                ],
                [
                    'id' => 104,
                    'email_id' => 6,
                    'filename' => 'client-summary.docx',
                    'size' => 98211,
                    'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
            ]
        );
    }

    private function findByEmailIdFromPiler(int $emailId): array
    {
        // TODO: Inspect real Piler schema to identify attachment metadata table and foreign key.
        // TODO: Replace this placeholder with PDO query for attachments by archived email ID.

        return [];
    }
}
