<?php

declare(strict_types=1);

namespace App\Services;

final class ClassificationService
{
    public function classifyContacts(array $contacts): array
    {
        $classified = [];

        foreach ($contacts as $contact) {
            $classified[] = [
                'email' => $contact,
                'category' => $this->guessCategory($contact),
                'model' => 'placeholder',
            ];
        }

        return $classified;
    }

    private function guessCategory(string $email): string
    {
        $domain = explode('@', $email)[1] ?? '';

        if (str_ends_with($domain, '.edu') || str_ends_with($domain, '.tn')) {
            return 'education-or-local-domain';
        }

        if (str_contains($domain, 'gov')) {
            return 'public-sector';
        }

        return 'business-or-general';
    }
}
