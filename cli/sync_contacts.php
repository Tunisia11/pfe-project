<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\AttachmentRepository;
use App\Repositories\EmailRepository;
use App\Services\ClassificationService;
use App\Services\ContactCleaningService;
use App\Services\ContactExtractionService;
use App\Services\EmailService;
use App\Services\ListmonkSyncService;
use App\Services\PilerDumpDataSource;
use App\Services\SyncHistoryService;

$f3 = Base::instance();
$appConfig = require __DIR__ . '/../config/app.php';
$f3->set('app', $appConfig);
require __DIR__ . '/../config/db.php';

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$defaultDumpPath = $projectRoot . '/piler_backup.sql';
$dumpPathFromEnv = trim((string) ($_ENV['SQL_DUMP_PATH'] ?? ''));
$dumpPath = $dumpPathFromEnv !== '' ? $dumpPathFromEnv : $defaultDumpPath;
if (!str_starts_with($dumpPath, '/')) {
    $dumpPath = $projectRoot . '/' . ltrim($dumpPath, '/');
}

$useSqlDumpDefault = is_file($dumpPath) ? 'true' : 'false';
$useSqlDump = filter_var($_ENV['USE_SQL_DUMP'] ?? $useSqlDumpDefault, FILTER_VALIDATE_BOOL);
$maxDumpEmails = (int) ($_ENV['SQL_DUMP_MAX_EMAILS'] ?? 0);
$maxDumpEmails = max(0, $maxDumpEmails);

if ($useSqlDump && $maxDumpEmails === 0) {
    $dumpMemoryLimit = trim((string) ($_ENV['SQL_DUMP_MEMORY_LIMIT'] ?? '1024M'));
    if ($dumpMemoryLimit !== '') {
        @ini_set('memory_limit', $dumpMemoryLimit);
    }
}

$dumpDataSource = null;
if ($useSqlDump && is_file($dumpPath)) {
    $dumpDataSource = new PilerDumpDataSource(
        $dumpPath,
        $projectRoot . '/storage/cache/piler_dump_cache.json',
        $maxDumpEmails
    );
}

$emailRepository = new EmailRepository($f3->get('db.pdo'), $dumpDataSource);
$attachmentRepository = new AttachmentRepository($f3->get('db.pdo'), $dumpDataSource);

$emailService = new EmailService(
    $emailRepository,
    $attachmentRepository,
    new ContactExtractionService(),
    new ContactCleaningService(),
    new ClassificationService(),
    new ListmonkSyncService()
);

$pipelineResult = $emailService->runContactPipeline();
$stats = $pipelineResult['stats'];

printf("\n=== Contact Sync Pipeline Summary ===\n");
printf("Total emails processed: %d\n", $pipelineResult['emails_processed']);
printf("Total extracted addresses: %d\n", $stats['total_extracted_addresses']);
printf("Valid contacts: %d\n", $stats['valid_contacts']);
printf("Duplicates removed: %d\n", $stats['duplicates_removed']);
printf("Ignored invalid/system addresses: %d\n", $stats['ignored_invalid_or_system_addresses']);
printf("Listmonk payload preview count: %d\n", $pipelineResult['listmonk_payload_preview_count']);

if ($pipelineResult['valid_contacts'] !== []) {
    printf("\nValid contacts sample:\n");
    foreach (array_slice($pipelineResult['valid_contacts'], 0, 10) as $contact) {
        printf("- %s\n", $contact);
    }
}

printf("\nPipeline completed.\n");

$syncHistoryService = new SyncHistoryService(__DIR__ . '/../storage/logs/sync_history.log');
$syncHistoryService->recordRun([
    'emails_processed' => $pipelineResult['emails_processed'],
    'total_extracted_addresses' => $stats['total_extracted_addresses'],
    'valid_contacts' => $stats['valid_contacts'],
    'duplicates_removed' => $stats['duplicates_removed'],
    'ignored_invalid_or_system_addresses' => $stats['ignored_invalid_or_system_addresses'],
]);
