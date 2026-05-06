<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use App\Repositories\ContactSourceRepository;
use App\Repositories\EmailRepository;
use App\Repositories\ExtractedContactRepository;
use App\Repositories\SyncRunRepository;

final class EmailService
{
    public function __construct(
        private readonly EmailRepository $emailRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly ContactExtractionService $contactExtractionService,
        private readonly ContactCleaningService $contactCleaningService,
        private readonly ClassificationService $classificationService,
        private readonly AiContactIntelligenceService $aiContactIntelligenceService,
        private readonly ListmonkSyncService $listmonkSyncService,
        private readonly ?ExtractedContactRepository $extractedContactRepository = null,
        private readonly ?ContactSourceRepository $contactSourceRepository = null,
        private readonly ?SyncRunRepository $syncRunRepository = null
    ) {
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function getEmails(int $limit = 10, int $offset = 0, array $filters = []): array
    {
        return $this->emailRepository->findAll($limit, $offset, $filters);
    }

    public function getEmailById(int $id): ?array
    {
        return $this->emailRepository->findById($id);
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function searchEmails(string $query, array $filters = []): array
    {
        return $this->emailRepository->search($query, $filters);
    }

    public function getAttachmentsByEmailId(int $emailId): ?array
    {
        $email = $this->emailRepository->findById($emailId);
        if ($email === null) {
            return null;
        }

        return $this->attachmentRepository->findByEmailId($emailId);
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $filters
     */
    public function runContactPipeline(
        ?int $emailLimit = null,
        int $offset = 0,
        array $filters = [],
        ?string $query = null,
        bool $includeSources = true
    ): array
    {
        $emails = $emailLimit === null
            ? $this->emailRepository->getAllForSync()
            : $this->emailRepository->findForExtraction($emailLimit, $offset, $filters, $query);
        $extractedAddresses = $this->contactExtractionService->extractFromEmails($emails);
        $sourceRows = $includeSources ? $this->contactExtractionService->extractSourcesFromEmails($emails) : [];
        $cleaningResult = $this->contactCleaningService->cleanAndDeduplicate($extractedAddresses);

        $validContacts = $cleaningResult['contacts'];
        $classifiedContacts = $this->classificationService->classifyContacts($validContacts);
        $sourceCounts = $this->buildSourceCounts($extractedAddresses, $validContacts);
        $aiIntelligence = $this->aiContactIntelligenceService->analyzeContacts($validContacts, [
            'source_counts' => $sourceCounts,
        ]);

        // Placeholder integration call kept for future usage.
        $listmonkPreview = $this->listmonkSyncService->preparePayload($validContacts);

        $persistence = $this->persistContactPipeline(
            $validContacts,
            $classifiedContacts,
            $sourceRows,
            $sourceCounts,
            count($emails),
            $cleaningResult['stats'],
            $aiIntelligence['stats'],
            $includeSources
        );

        return [
            'emails_processed' => count($emails),
            'extracted_addresses' => $extractedAddresses,
            'valid_contacts' => $validContacts,
            'classified_contacts' => $classifiedContacts,
            'ai_contacts' => $aiIntelligence['contacts'],
            'ai_summary' => $aiIntelligence['stats'],
            'ai_status' => [
                'enabled' => $aiIntelligence['enabled'],
                'provider' => $aiIntelligence['provider'],
                'mode' => $aiIntelligence['mode'],
                'notice' => $aiIntelligence['notice'],
                'batch_size' => $aiIntelligence['batch_size'],
            ],
            'listmonk_payload_preview_count' => count($listmonkPreview),
            'stats' => $cleaningResult['stats'],
            'persistence' => $persistence,
        ];
    }

    private function persistContactPipeline(
        array $validContacts,
        array $classifiedContacts,
        array $sourceRows,
        array $sourceCounts,
        int $emailsProcessed,
        array $stats,
        array $aiStats,
        bool $includeSources
    ): array {
        if ($this->extractedContactRepository === null) {
            return [
                'enabled' => false,
                'contacts_saved' => 0,
                'created_contacts' => 0,
                'updated_contacts' => 0,
                'sources_added' => 0,
                'sync_run_id' => null,
            ];
        }

        $classificationByEmail = [];
        foreach ($classifiedContacts as $classification) {
            $email = (string) ($classification['email'] ?? '');
            if ($email !== '') {
                $classificationByEmail[$email] = (string) ($classification['category'] ?? '');
            }
        }

        $persistedByEmail = [];
        $createdContacts = 0;
        $updatedContacts = 0;
        foreach ($validContacts as $contact) {
            $email = mb_strtolower(trim((string) $contact));
            $result = $this->extractedContactRepository->upsert(
                $email,
                $classificationByEmail[$email] ?? null,
                (int) ($sourceCounts[$email] ?? 0)
            );
            $row = $result['contact'] ?? [];

            if (isset($row['id'])) {
                $persistedByEmail[$email] = (int) $row['id'];
                if (($result['created'] ?? false) === true) {
                    $createdContacts++;
                } elseif (($result['updated'] ?? false) === true) {
                    $updatedContacts++;
                }
            }
        }

        $sourcesAdded = 0;
        if ($includeSources && $this->contactSourceRepository !== null) {
            foreach ($sourceRows as $source) {
                $email = mb_strtolower(trim((string) ($source['email'] ?? '')));
                if (!isset($persistedByEmail[$email])) {
                    continue;
                }

                if ($this->contactSourceRepository->insertIfMissing(
                    $persistedByEmail[$email],
                    (int) ($source['email_archive_id'] ?? 0),
                    (string) ($source['source_field'] ?? 'unknown')
                )) {
                    $sourcesAdded++;
                }
            }
        }

        $syncRunId = null;
        if ($this->syncRunRepository !== null) {
            $syncRunId = $this->syncRunRepository->create([
                'type' => 'contact_extract',
                'status' => 'success',
                'emails_processed' => $emailsProcessed,
                'total_extracted_addresses' => (int) ($stats['total_extracted_addresses'] ?? 0),
                'valid_contacts' => (int) ($stats['valid_contacts'] ?? 0),
                'duplicates_removed' => (int) ($stats['duplicates_removed'] ?? 0),
                'ignored_invalid_or_system_addresses' => (int) ($stats['ignored_invalid_or_system_addresses'] ?? 0),
                'unique_contacts' => count($persistedByEmail),
                'message' => 'Contact extraction persisted to app database.',
                'payload' => [
                    'ai_summary' => $aiStats,
                ],
            ]);
        }

        return [
            'enabled' => true,
            'contacts_saved' => count($persistedByEmail),
            'created_contacts' => $createdContacts,
            'updated_contacts' => $updatedContacts,
            'sources_added' => $sourcesAdded,
            'sync_run_id' => $syncRunId,
        ];
    }

    private function buildSourceCounts(array $extractedAddresses, array $validContacts): array
    {
        $validLookup = array_fill_keys($validContacts, true);
        $counts = [];

        foreach ($extractedAddresses as $address) {
            $normalized = mb_strtolower(trim((string) $address));
            if (!isset($validLookup[$normalized])) {
                continue;
            }

            $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        }

        return $counts;
    }
}
