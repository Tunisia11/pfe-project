<?php

declare(strict_types=1);

namespace App\Services;

final class ListmonkSyncService
{
    public function preparePayload(array $contacts): array
    {
        // TODO: Map cleaned contacts into Listmonk subscriber payload format.
        return $contacts;
    }

    public function sync(array $contacts): array
    {
        // TODO: Integrate with Listmonk API (HTTP client, auth token, list IDs, retries).
        return [
            'status' => 'not_implemented',
            'synced' => 0,
            'failed' => count($contacts),
        ];
    }
}
