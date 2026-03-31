<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmailService;
use App\Services\ResponseHelper;
use Base;

final class AttachmentController
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function index(Base $f3, array $params): void
    {
        $emailId = (int) ($params['id'] ?? 0);
        if ($emailId <= 0) {
            ResponseHelper::error($f3, 'Invalid email id', 400);
            return;
        }

        $attachments = $this->emailService->getAttachmentsByEmailId($emailId);
        if ($attachments === null) {
            ResponseHelper::error($f3, 'Email not found', 404);
            return;
        }

        ResponseHelper::success($f3, [
            'email_id' => $emailId,
            'count' => count($attachments),
            'data' => $attachments,
        ]);
    }
}
