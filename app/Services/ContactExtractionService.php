<?php

declare(strict_types=1);

namespace App\Services;

final class ContactExtractionService
{
    public function extractFromEmails(array $emails): array
    {
        $allAddresses = [];

        foreach ($emails as $email) {
            $allAddresses = array_merge($allAddresses, $this->extractFromEmail($email));
        }

        return $allAddresses;
    }

    public function extractFromEmail(array $email): array
    {
        $addresses = [];

        foreach (['from', 'to', 'cc'] as $field) {
            if (!array_key_exists($field, $email)) {
                continue;
            }

            $addresses = array_merge($addresses, $this->extractAddressesFromField($email[$field]));
        }

        return $addresses;
    }

    private function extractAddressesFromField(mixed $fieldValue): array
    {
        if (is_array($fieldValue)) {
            $addresses = [];
            foreach ($fieldValue as $value) {
                $addresses = array_merge($addresses, $this->extractAddressesFromField($value));
            }

            return $addresses;
        }

        if (!is_string($fieldValue)) {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fieldValue, $matches);

        return $matches[0] ?? [];
    }
}
