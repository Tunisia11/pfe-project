<?php

declare(strict_types=1);

namespace App\Models;

final class Attachment
{
    public function __construct(
        public int $id,
        public int $emailId,
        public string $filename,
        public int $size,
        public string $type
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['id'] ?? 0),
            (int) ($data['email_id'] ?? 0),
            (string) ($data['filename'] ?? ''),
            (int) ($data['size'] ?? 0),
            (string) ($data['type'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email_id' => $this->emailId,
            'filename' => $this->filename,
            'size' => $this->size,
            'type' => $this->type,
        ];
    }
}
