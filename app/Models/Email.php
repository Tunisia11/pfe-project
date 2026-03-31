<?php

declare(strict_types=1);

namespace App\Models;

final class Email
{
    public function __construct(
        public int $id,
        public string $subject,
        public string $from,
        public array $to,
        public array $cc,
        public string $date,
        public string $bodyPreview
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['id'] ?? 0),
            (string) ($data['subject'] ?? ''),
            (string) ($data['from'] ?? ''),
            array_values((array) ($data['to'] ?? [])),
            array_values((array) ($data['cc'] ?? [])),
            (string) ($data['date'] ?? ''),
            (string) ($data['body_preview'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'date' => $this->date,
            'body_preview' => $this->bodyPreview,
        ];
    }
}
