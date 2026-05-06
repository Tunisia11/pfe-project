<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ContactSourceRepository;
use App\Repositories\ExtractedContactRepository;
use App\Repositories\SyncRunRepository;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\ResponseHelper;
use Base;

final class ContactController
{
    private const ALLOWED_STATUSES = ['pending', 'approved', 'ignored', 'blocked'];

    public function __construct(
        private readonly ExtractedContactRepository $contacts,
        private readonly ContactSourceRepository $contactSources,
        private readonly SyncRunRepository $syncRuns,
        private readonly EmailService $emailService,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function index(Base $f3): void
    {
        $status = trim((string) $f3->get('GET.status'));
        $status = $status === '' ? null : $status;
        $query = trim((string) $f3->get('GET.q'));
        $limit = $this->integer($f3->get('GET.limit'), 100, 1, 1000);
        $offset = $this->integer($f3->get('GET.offset'), 0, 0, 5000000);
        if (!$this->validStatus($status, true)) {
            ResponseHelper::error($f3, 'Invalid contact status', 422, ['allowed' => array_merge(self::ALLOWED_STATUSES, ['all'])]);
            return;
        }

        ResponseHelper::success($f3, [
            'data' => [
                'contacts' => $this->contacts->list($status, $query === '' ? null : $query, $limit, $offset),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => $this->contacts->countFiltered($status, $query === '' ? null : $query),
                ],
            ],
        ]);
    }

    public function extract(Base $f3): void
    {
        @set_time_limit(120);
        $payload = $this->payload();
        $limit = $this->integer($payload['limit'] ?? $f3->get('GET.limit'), 1000, 1, 10000);
        $offset = $this->integer($payload['offset'] ?? $f3->get('GET.offset'), 0, 0, 5000000);
        $query = trim((string) ($payload['q'] ?? $f3->get('GET.q')));
        $includeSources = $this->boolean($payload['include_sources'] ?? $f3->get('GET.include_sources'), true);
        $filters = [
            'date_from' => $this->date($payload['date_from'] ?? $f3->get('GET.date_from')),
            'date_to' => $this->date($payload['date_to'] ?? $f3->get('GET.date_to')),
        ];

        $result = $this->emailService->runContactPipeline($limit, $offset, $filters, $query === '' ? null : $query, $includeSources);
        $user = $f3->get('admin.user') ?: null;
        $this->auditLog->log(
            'contact_extract',
            is_array($user) ? (int) ($user['id'] ?? 0) : null,
            'sync_run',
            isset($result['persistence']['sync_run_id']) ? (string) $result['persistence']['sync_run_id'] : null,
            [
                'emails_processed' => $result['emails_processed'] ?? 0,
                'contacts_saved' => $result['persistence']['contacts_saved'] ?? 0,
            ],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        ResponseHelper::success($f3, [
            'data' => [
                'stats' => [
                    'emails_processed' => (int) ($result['emails_processed'] ?? 0),
                    'total_extracted_addresses' => (int) ($result['stats']['total_extracted_addresses'] ?? 0),
                    'valid_contacts' => (int) ($result['stats']['valid_contacts'] ?? 0),
                    'duplicates_removed' => (int) ($result['stats']['duplicates_removed'] ?? 0),
                    'ignored_invalid_or_system_addresses' => (int) ($result['stats']['ignored_invalid_or_system_addresses'] ?? 0),
                    'unique_contacts' => (int) ($result['persistence']['contacts_saved'] ?? 0),
                    'created_contacts' => (int) ($result['persistence']['created_contacts'] ?? 0),
                    'updated_contacts' => (int) ($result['persistence']['updated_contacts'] ?? 0),
                    'sources_added' => (int) ($result['persistence']['sources_added'] ?? 0),
                ],
                'sync_run_id' => $result['persistence']['sync_run_id'] ?? null,
            ],
        ]);
    }

    public function updateStatus(Base $f3, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            ResponseHelper::error($f3, 'Invalid contact id', 400);
            return;
        }

        $payload = $this->payload();
        $status = (string) ($payload['status'] ?? '');
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            ResponseHelper::error($f3, 'Invalid contact status', 422, [
                'allowed' => self::ALLOWED_STATUSES,
            ]);
            return;
        }

        $contact = $this->contacts->updateStatus($id, $status, $notes === '' ? null : $notes);
        if ($contact === null) {
            ResponseHelper::error($f3, 'Contact not found', 404);
            return;
        }

        $user = $f3->get('admin.user') ?: null;
        $this->auditLog->log(
            'contact_status_update',
            is_array($user) ? (int) ($user['id'] ?? 0) : null,
            'extracted_contact',
            (string) $id,
            ['status' => $status, 'notes' => $notes],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        ResponseHelper::success($f3, ['data' => $contact]);
    }

    public function stats(Base $f3): void
    {
        ResponseHelper::success($f3, ['data' => $this->contacts->stats()]);
    }

    public function exportCsv(Base $f3): void
    {
        $status = trim((string) $f3->get('GET.status'));
        if ($status === '') {
            $status = 'approved';
        }
        $query = trim((string) $f3->get('GET.q'));
        $includeBlocked = $this->boolean($f3->get('GET.include_blocked'), false);

        if ($status !== 'all' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            ResponseHelper::error($f3, 'Invalid export status', 422);
            return;
        }

        $rows = $this->contacts->exportRows($status, $query === '' ? null : $query, $includeBlocked);
        $user = $f3->get('admin.user') ?: null;
        $this->auditLog->log(
            'contact_export',
            is_array($user) ? (int) ($user['id'] ?? 0) : null,
            'extracted_contact',
            null,
            ['status' => $status, 'q' => $query === '' ? null : $query, 'include_blocked' => $includeBlocked, 'rows' => count($rows)],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        $filename = 'piler_contacts_' . gmdate('Ymd_His') . '.csv';
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $f3->set('HEADERS.Content-Type', 'text/csv; charset=utf-8');
        $f3->set('HEADERS.Content-Disposition', 'attachment; filename="' . $filename . '"');

        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            return;
        }

        fputcsv($handle, ['email', 'domain', 'category', 'status', 'source_count', 'notes', 'first_seen_at', 'last_seen_at'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['email'] ?? '',
                $row['domain'] ?? '',
                $row['category'] ?? '',
                $row['status'] ?? '',
                $row['source_count'] ?? 0,
                $row['notes'] ?? '',
                $row['first_seen_at'] ?? '',
                $row['last_seen_at'] ?? '',
            ], ',', '"', '');
        }
        fclose($handle);
    }

    public function sources(Base $f3, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            ResponseHelper::error($f3, 'Invalid contact id', 400);
            return;
        }

        if ($this->contacts->findById($id) === null) {
            ResponseHelper::error($f3, 'Contact not found', 404);
            return;
        }

        $limit = $this->integer($f3->get('GET.limit'), 50, 1, 200);
        $offset = $this->integer($f3->get('GET.offset'), 0, 0, 5000000);

        ResponseHelper::success($f3, [
            'data' => [
                'contact_id' => $id,
                'sources' => $this->contactSources->listByContactId($id, $limit, $offset),
            ],
        ]);
    }

    public function syncRuns(Base $f3): void
    {
        $limit = $this->integer($f3->get('GET.limit'), 20, 1, 100);
        ResponseHelper::success($f3, [
            'data' => $this->syncRuns->recent($limit),
        ]);
    }

    private function payload(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function integer(mixed $value, int $default, int $min, int $max): int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($intValue === false) {
            return $default;
        }

        return max($min, min((int) $intValue, $max));
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function validStatus(?string $status, bool $allowAll): bool
    {
        if ($status === null || $status === '') {
            return true;
        }

        if ($allowAll && $status === 'all') {
            return true;
        }

        return in_array($status, self::ALLOWED_STATUSES, true);
    }

    private function date(mixed $value): ?string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }
}
