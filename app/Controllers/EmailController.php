<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmailService;
use App\Services\ResponseHelper;
use Base;

final class EmailController
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function index(Base $f3): void
    {
        $limit = $this->sanitizeInteger($f3->get('GET.limit'), 10, 1, 100);
        $offset = $this->sanitizeInteger($f3->get('GET.offset'), 0, 0, 5000000);

        $emails = $this->emailService->getEmails($limit, $offset);

        ResponseHelper::success($f3, [
            'data' => $emails,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($emails),
            ],
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

        $results = $this->emailService->searchEmails($query);

        ResponseHelper::success($f3, [
            'query' => $query,
            'count' => count($results),
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
}
