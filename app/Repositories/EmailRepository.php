<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Email;
use App\Services\PilerDumpDataSource;
use PDO;

final class EmailRepository
{
    private const MAX_PAGE_LIMIT = 1000;

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?PilerDumpDataSource $dumpDataSource = null
    ) {
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function findAll(int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, min($limit, self::MAX_PAGE_LIMIT));
        $offset = max(0, $offset);

        if ($this->pdo !== null) {
            return $this->findAllFromPiler($limit, $offset);
        }

        if ($this->dumpDataSource !== null) {
            $emails = $this->filterByDateRange($this->dumpDataSource->getEmails(), $filters);

            return array_slice($emails, $offset, $limit);
        }

        return array_slice($this->filterByDateRange($this->mockEmails(), $filters), $offset, $limit);
    }

    public function findById(int $id): ?array
    {
        if ($this->pdo !== null) {
            return $this->findByIdFromPiler($id);
        }

        if ($this->dumpDataSource !== null) {
            foreach ($this->dumpDataSource->getEmails() as $email) {
                if ((int) $email['id'] === $id) {
                    return $email;
                }
            }

            return null;
        }

        foreach ($this->mockEmails() as $email) {
            if ($email['id'] === $id) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function search(string $query, array $filters = []): array
    {
        if ($this->pdo !== null) {
            return $this->searchFromPiler($query);
        }

        $emails = $this->dumpDataSource !== null
            ? $this->dumpDataSource->getEmails()
            : $this->mockEmails();

        $needle = mb_strtolower($query);

        $matches = array_values(array_filter(
            $emails,
            static function (array $email) use ($needle): bool {
                $from = mb_strtolower($email['from']);
                $subject = mb_strtolower($email['subject']);
                $to = mb_strtolower(implode(' ', $email['to']));

                return str_contains($subject, $needle)
                    || str_contains($from, $needle)
                    || str_contains($to, $needle);
            }
        ));

        return $this->filterByDateRange($matches, $filters);
    }

    public function getAllForSync(): array
    {
        if ($this->pdo !== null) {
            return $this->findAllFromPiler(1000, 0);
        }

        if ($this->dumpDataSource !== null) {
            return $this->dumpDataSource->getEmails();
        }

        return $this->mockEmails();
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function findForExtraction(int $limit = 1000, int $offset = 0, array $filters = [], ?string $query = null): array
    {
        $limit = max(1, min($limit, 10000));
        $offset = max(0, $offset);

        if ($this->pdo !== null) {
            $emails = $query !== null && trim($query) !== ''
                ? $this->searchFromPiler($query)
                : $this->findAllFromPiler($limit, $offset);

            return array_slice($this->filterByDateRange($emails, $filters), 0, $limit);
        }

        $emails = $this->dumpDataSource !== null
            ? $this->dumpDataSource->getEmails()
            : $this->mockEmails();

        if ($query !== null && trim($query) !== '') {
            $emails = $this->filterByQuery($emails, $query);
        }

        $emails = $this->filterByDateRange($emails, $filters);

        return array_slice($emails, $offset, $limit);
    }

    private function mockEmails(): array
    {
        return array_map(
            static fn (array $row): array => Email::fromArray($row)->toArray(),
            [
                [
                    'id' => 1,
                    'subject' => 'Project Kickoff Meeting',
                    'from' => 'Alice Manager <alice.manager@company.tn>',
                    'to' => ['team.dev@company.tn', 'noreply@company.tn'],
                    'cc' => ['bob.dev@company.tn'],
                    'date' => '2026-02-10 09:30:00',
                    'body_preview' => 'Kickoff notes and milestones for Q1 deliverables.',
                ],
                [
                    'id' => 2,
                    'subject' => 'Re: API Logs Export',
                    'from' => 'bob.dev@company.tn',
                    'to' => ['alice.manager@company.tn', 'support@company.tn'],
                    'cc' => ['no-reply@alerts.company.tn'],
                    'date' => '2026-02-11 15:12:00',
                    'body_preview' => 'Attached logs for archive extraction validation.',
                ],
                [
                    'id' => 3,
                    'subject' => 'Internship Convention Signature',
                    'from' => 'rh@university.tn',
                    'to' => ['student.pfe@university.tn'],
                    'cc' => ['rh@university.tn', 'mailER-daemon@university.tn'],
                    'date' => '2026-02-12 10:00:00',
                    'body_preview' => 'Please review and sign the convention document.',
                ],
                [
                    'id' => 4,
                    'subject' => 'Marketing campaign contact list',
                    'from' => 'marketing.lead@agency.tn',
                    'to' => ['crm.owner@company.tn', 'sales@company.tn'],
                    'cc' => ['alice.manager@company.tn', 'invalid-email-address'],
                    'date' => '2026-02-13 14:45:00',
                    'body_preview' => 'Draft list of contacts for next Listmonk push.',
                ],
                [
                    'id' => 5,
                    'subject' => 'Archive extraction dry run',
                    'from' => 'ops@company.tn',
                    'to' => ['student.pfe@university.tn', 'ops@company.tn'],
                    'cc' => ['noreply@system.tn'],
                    'date' => '2026-02-14 08:10:00',
                    'body_preview' => 'Dry-run completed. No blocking error detected.',
                ],
                [
                    'id' => 6,
                    'subject' => 'Client follow-up',
                    'from' => 'sales@company.tn',
                    'to' => ['client.one@example.com', 'client.two@example.com'],
                    'cc' => ['support@company.tn'],
                    'date' => '2026-02-15 11:20:00',
                    'body_preview' => 'Follow-up after the product demonstration.',
                ],
            ]
        );
    }

    private function findAllFromPiler(int $limit, int $offset): array
    {
        // TODO: Map real Piler table and column names after schema inspection.
        // TODO: Replace this placeholder with a PDO query using LIMIT/OFFSET.
        // Example skeleton (not executable without schema mapping):
        // SELECT id, sender, recipients, cc, subject, archived_at FROM <piler_table> LIMIT :limit OFFSET :offset

        return [];
    }

    private function findByIdFromPiler(int $id): ?array
    {
        // TODO: Map Piler primary key and fields after schema analysis.
        // TODO: Build PDO query for one archived email by ID.

        return null;
    }

    private function searchFromPiler(string $query): array
    {
        // TODO: Map Piler searchable columns (subject, sender, recipients) after schema inspection.
        // TODO: Build PDO LIKE/full-text query depending on real DB capabilities.

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $emails
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByQuery(array $emails, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter(
            $emails,
            static function (array $email) use ($needle): bool {
                $from = mb_strtolower((string) ($email['from'] ?? ''));
                $subject = mb_strtolower((string) ($email['subject'] ?? ''));
                $to = mb_strtolower(implode(' ', is_array($email['to'] ?? null) ? $email['to'] : []));
                $cc = mb_strtolower(implode(' ', is_array($email['cc'] ?? null) ? $email['cc'] : []));

                return str_contains($subject, $needle)
                    || str_contains($from, $needle)
                    || str_contains($to, $needle)
                    || str_contains($cc, $needle);
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $emails
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByDateRange(array $emails, array $filters): array
    {
        $from = $this->dateBoundary($filters['date_from'] ?? null, false);
        $to = $this->dateBoundary($filters['date_to'] ?? null, true);

        if ($from === null && $to === null) {
            return $emails;
        }

        return array_values(array_filter(
            $emails,
            static function (array $email) use ($from, $to): bool {
                $timestamp = strtotime((string) ($email['date'] ?? ''));
                if ($timestamp === false) {
                    return false;
                }

                if ($from !== null && $timestamp < $from) {
                    return false;
                }

                if ($to !== null && $timestamp > $to) {
                    return false;
                }

                return true;
            }
        ));
    }

    private function dateBoundary(?string $value, bool $endOfDay): ?int
    {
        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }

        $time = strtotime($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));

        return $time === false ? null : $time;
    }
}
