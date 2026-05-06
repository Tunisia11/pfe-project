<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmailService;
use App\Services\ResponseHelper;
use Base;

final class EmailController
{
    private const MAX_PAGE_LIMIT = 1000;

    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function index(Base $f3): void
    {
        $limit = $this->sanitizeInteger($f3->get('GET.limit'), 10, 1, self::MAX_PAGE_LIMIT);
        $offset = $this->sanitizeInteger($f3->get('GET.offset'), 0, 0, 5000000);
        $filters = $this->dateFilters($f3);

        $emails = $this->emailService->getEmails($limit, $offset, $filters);

        ResponseHelper::success($f3, [
            'data' => $emails,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($emails),
            ],
            'filters' => $filters,
        ]);
    }

    public function show(Base $f3, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            ResponseHelper::error($f3, 'Invalid email id', 400);
            return;
        }

        $email = $this->emailService->getEmailById($id);
        if ($email === null) {
            ResponseHelper::error($f3, 'Email not found', 404);
            return;
        }

        ResponseHelper::success($f3, [
            'data' => $email,
        ]);
    }

    public function search(Base $f3): void
    {
        $query = trim((string) $f3->get('GET.q'));
        if ($query === '') {
            ResponseHelper::error($f3, 'Query parameter q is required', 422);
            return;
        }

        $filters = $this->dateFilters($f3);
        $results = $this->emailService->searchEmails($query, $filters);

        ResponseHelper::success($f3, [
            'query' => $query,
            'count' => count($results),
            'filters' => $filters,
            'data' => $results,
        ]);
    }

    private function sanitizeInteger(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($intValue === false) {
            return $default;
        }

        return max($min, min((int) $intValue, $max));
    }

    /**
     * @return array{date_from: string|null, date_to: string|null}
     */
    private function dateFilters(Base $f3): array
    {
        return [
            'date_from' => $this->sanitizeDate($f3->get('GET.date_from')),
            'date_to' => $this->sanitizeDate($f3->get('GET.date_to')),
        ];
    }

    private function sanitizeDate(mixed $value): ?string
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
