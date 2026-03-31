<?php

declare(strict_types=1);

namespace App\Services;

final class SyncHistoryService
{
    public function __construct(private readonly string $historyFilePath)
    {
    }

    public function recordRun(array $summary): void
    {
        $directory = dirname($this->historyFilePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'timestamp' => date('c'),
            'summary' => $summary,
        ];

        file_put_contents(
            $this->historyFilePath,
            json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}
