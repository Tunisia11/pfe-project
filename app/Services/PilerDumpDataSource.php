<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PilerDumpDataSource
{
    private ?array $data = null;

    public function __construct(
        private readonly string $dumpPath,
        private readonly string $cachePath,
        private readonly int $maxEmails = 1000
    ) {
    }

    public function getEmails(): array
    {
        return $this->load()['emails'];
    }

    public function getAttachmentsByEmailId(int $emailId): array
    {
        $map = $this->load()['attachments_by_email_id'];
        return $map[(string) $emailId] ?? [];
    }

    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->dumpPath)) {
            throw new RuntimeException(sprintf('SQL dump file not found: %s', $this->dumpPath));
        }

        $sourceMtime = (int) filemtime($this->dumpPath);

        if (is_file($this->cachePath)) {
            $raw = file_get_contents($this->cachePath);
            $cached = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($cached)
                && (int) ($cached['source_mtime'] ?? 0) === $sourceMtime
                && (int) ($cached['max_emails'] ?? 0) === $this->maxEmails
            ) {
                $this->data = $cached;
                return $this->data;
            }
        }

        $built = $this->buildFromDump();
        $this->persistCache($built, $sourceMtime);

        $this->data = $built;
        return $this->data;
    }

    private function persistCache(array $data, int $sourceMtime): void
    {
        $directory = dirname($this->cachePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'source_mtime' => $sourceMtime,
            'max_emails' => $this->maxEmails,
            'emails' => $data['emails'],
            'attachments_by_email_id' => $data['attachments_by_email_id'],
        ];

        file_put_contents(
            $this->cachePath,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    private function buildFromDump(): array
    {
        $emailsById = [];
        $pilerIdToEmailId = [];
        $attachmentsByEmailId = [];

        $this->parseInsertTuples('metadata', function (array $fields) use (&$emailsById, &$pilerIdToEmailId): bool {
            if ($this->hasEmailLimit() && count($emailsById) >= $this->maxEmails) {
                return false;
            }

            if (count($fields) < 14) {
                return true;
            }

            $id = (int) ($fields[0] ?? 0);
            $from = (string) ($fields[1] ?? '');
            $subject = (string) ($fields[3] ?? '');
            $sent = (int) ($fields[6] ?? 0);
            $pilerId = (string) ($fields[13] ?? '');

            $emailsById[$id] = [
                'id' => $id,
                'subject' => $subject,
                'from' => $from,
                'to' => [],
                'cc' => [],
                'date' => $sent > 0 ? gmdate('Y-m-d H:i:s', $sent) : '',
                'body_preview' => '',
                '_piler_id' => $pilerId,
            ];

            if ($pilerId !== '') {
                $pilerIdToEmailId[$pilerId] = $id;
            }

            return true;
        });

        $this->parseInsertTuples('rcpt', function (array $fields) use (&$emailsById): bool {
            if (count($fields) < 2) {
                return true;
            }

            $id = (int) ($fields[0] ?? 0);
            $recipient = (string) ($fields[1] ?? '');
            if ($recipient === '' || !isset($emailsById[$id])) {
                return true;
            }

            if (!in_array($recipient, $emailsById[$id]['to'], true)) {
                $emailsById[$id]['to'][] = $recipient;
            }

            return true;
        });

        $this->parseInsertTuples('attachment', function (array $fields) use (&$pilerIdToEmailId, &$attachmentsByEmailId): bool {
            if (count($fields) < 7) {
                return true;
            }

            $pilerId = (string) ($fields[1] ?? '');
            if ($pilerId === '' || !isset($pilerIdToEmailId[$pilerId])) {
                return true;
            }

            $emailId = $pilerIdToEmailId[$pilerId];
            $attachment = [
                'id' => (int) ($fields[0] ?? 0),
                'email_id' => $emailId,
                'filename' => (string) ($fields[3] ?? ''),
                'size' => (int) ($fields[6] ?? 0),
                'type' => (string) ($fields[4] ?? ''),
            ];

            $key = (string) $emailId;
            if (!isset($attachmentsByEmailId[$key])) {
                $attachmentsByEmailId[$key] = [];
            }
            $attachmentsByEmailId[$key][] = $attachment;

            return true;
        });

        ksort($emailsById);
        $emails = [];
        foreach ($emailsById as $email) {
            unset($email['_piler_id']);
            $emails[] = $email;
        }

        return [
            'emails' => $emails,
            'attachments_by_email_id' => $attachmentsByEmailId,
        ];
    }

    private function parseInsertTuples(string $table, callable $onTuple): void
    {
        $handle = fopen($this->dumpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open SQL dump: %s', $this->dumpPath));
        }

        $needle = sprintf('INSERT INTO `%s` VALUES', $table);

        $insideInsert = false;
        $collectingTuple = false;
        $tupleBuffer = '';
        $nestedDepth = 0;
        $inQuote = false;
        $escape = false;
        $continue = true;

        while ($continue && ($line = fgets($handle)) !== false) {
            if (!$insideInsert) {
                $position = strpos($line, $needle);
                if ($position === false) {
                    continue;
                }

                $insideInsert = true;
                $chunk = substr($line, $position + strlen($needle));
            } else {
                $chunk = $line;
            }

            $length = strlen($chunk);
            for ($i = 0; $i < $length && $continue; $i++) {
                $char = $chunk[$i];

                if ($collectingTuple) {
                    if ($escape) {
                        $tupleBuffer .= $char;
                        $escape = false;
                        continue;
                    }

                    if ($char === '\\' && $inQuote) {
                        $tupleBuffer .= $char;
                        $escape = true;
                        continue;
                    }

                    if ($char === "'") {
                        $inQuote = !$inQuote;
                        $tupleBuffer .= $char;
                        continue;
                    }

                    if (!$inQuote) {
                        if ($char === '(') {
                            $nestedDepth++;
                            $tupleBuffer .= $char;
                            continue;
                        }

                        if ($char === ')') {
                            if ($nestedDepth === 0) {
                                $fields = $this->parseTupleFields($tupleBuffer);
                                $continue = (bool) $onTuple($fields);
                                $collectingTuple = false;
                                $tupleBuffer = '';
                                continue;
                            }

                            $nestedDepth--;
                            $tupleBuffer .= $char;
                            continue;
                        }
                    }

                    $tupleBuffer .= $char;
                    continue;
                }

                if ($char === '(') {
                    $collectingTuple = true;
                    $tupleBuffer = '';
                    $nestedDepth = 0;
                    $inQuote = false;
                    $escape = false;
                    continue;
                }

                if ($char === ';') {
                    $insideInsert = false;
                    break;
                }
            }
        }

        fclose($handle);
    }

    private function parseTupleFields(string $tuple): array
    {
        $fields = [];
        $current = '';
        $inQuote = false;
        $escape = false;
        $nestedDepth = 0;

        $length = strlen($tuple);
        for ($i = 0; $i < $length; $i++) {
            $char = $tuple[$i];

            if ($escape) {
                $current .= $char;
                $escape = false;
                continue;
            }

            if ($char === '\\' && $inQuote) {
                $current .= $char;
                $escape = true;
                continue;
            }

            if ($char === "'") {
                $inQuote = !$inQuote;
                $current .= $char;
                continue;
            }

            if (!$inQuote) {
                if ($char === '(') {
                    $nestedDepth++;
                } elseif ($char === ')' && $nestedDepth > 0) {
                    $nestedDepth--;
                } elseif ($char === ',' && $nestedDepth === 0) {
                    $fields[] = $this->normalizeValue($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if ($current !== '' || $tuple === '') {
            $fields[] = $this->normalizeValue($current);
        }

        return $fields;
    }

    private function normalizeValue(string $raw): mixed
    {
        $value = trim($raw);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        if ($value[0] === "'" && str_ends_with($value, "'")) {
            $inner = substr($value, 1, -1);
            $inner = str_replace(
                ["\\\\", "\\'", "\\n", "\\r", "\\t", "\\0"],
                ["\\", "'", "\n", "\r", "\t", "\0"],
                $inner
            );

            return $this->sanitizeUtf8($inner);
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private function sanitizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($converted === false) {
            return '';
        }

        return $converted;
    }

    private function hasEmailLimit(): bool
    {
        return $this->maxEmails > 0;
    }
}
