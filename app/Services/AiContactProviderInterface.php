<?php

declare(strict_types=1);

namespace App\Services;

interface AiContactProviderInterface
{
    /**
     * @param array<int, string> $contacts
     * @param array{source_counts?: array<string, int>} $context
     *
     * @return array<int, array<string, mixed>>
     */
    public function analyzeContacts(array $contacts, array $context = []): array;

    public function getName(): string;
}
