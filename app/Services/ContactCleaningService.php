<?php

declare(strict_types=1);

namespace App\Services;

final class ContactCleaningService
{
    public function cleanAndDeduplicate(array $addresses): array
    {
        $unique = [];
        $duplicatesRemoved = 0;
        $ignoredInvalidOrSystem = 0;

        foreach ($addresses as $rawAddress) {
            $normalized = mb_strtolower(trim((string) $rawAddress));

            if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                $ignoredInvalidOrSystem++;
                continue;
            }

            if ($this->isSystemAddress($normalized)) {
                $ignoredInvalidOrSystem++;
                continue;
            }

            if (isset($unique[$normalized])) {
                $duplicatesRemoved++;
                continue;
            }

            $unique[$normalized] = true;
        }

        $contacts = array_keys($unique);

        return [
            'contacts' => $contacts,
            'stats' => [
                'total_extracted_addresses' => count($addresses),
                'valid_contacts' => count($contacts),
                'duplicates_removed' => $duplicatesRemoved,
                'ignored_invalid_or_system_addresses' => $ignoredInvalidOrSystem,
            ],
        ];
    }

    private function isSystemAddress(string $email): bool
    {
        $localPart = explode('@', $email)[0] ?? '';

        return (bool) preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)$/i', $localPart);
    }
}
