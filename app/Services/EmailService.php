<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use App\Repositories\EmailRepository;

final class EmailService
{
    public function __construct(
        private readonly EmailRepository $emailRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly ContactExtractionService $contactExtractionService,
        private readonly ContactCleaningService $contactCleaningService,
        private readonly ClassificationService $classificationService,
        private readonly ListmonkSyncService $listmonkSyncService
    ) {
    }

    public function getEmails(int $limit = 10, int $offset = 0): array
    {
        return $this->emailRepository->findAll($limit, $offset);
    }

    public function getEmailById(int $id): ?array
    {
        return $this->emailRepository->findById($id);
    }

    public function searchEmails(string $query): array
    {
        return $this->emailRepository->search($query);
    }

    public function getAttachmentsByEmailId(int $emailId): ?array
    {
        $email = $this->emailRepository->findById($emailId);
        if ($email === null) {
            return null;
        }

        return $this->attachmentRepository->findByEmailId($emailId);
    }

    public function runContactPipeline(): array
    {
        $emails = $this->emailRepository->getAllForSync();
        $extractedAddresses = $this->contactExtractionService->extractFromEmails($emails);
        $cleaningResult = $this->contactCleaningService->cleanAndDeduplicate($extractedAddresses);

        $validContacts = $cleaningResult['contacts'];
        $classifiedContacts = $this->classificationService->classifyContacts($validContacts);

        // Placeholder integration call kept for future usage.
        $listmonkPreview = $this->listmonkSyncService->preparePayload($validContacts);

        return [
            'emails_processed' => count($emails),
            'extracted_addresses' => $extractedAddresses,
            'valid_contacts' => $validContacts,
            'classified_contacts' => $classifiedContacts,
            'listmonk_payload_preview_count' => count($listmonkPreview),
            'stats' => $cleaningResult['stats'],
        ];
    }
}
