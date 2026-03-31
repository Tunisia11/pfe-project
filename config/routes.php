<?php

declare(strict_types=1);

use App\Controllers\AttachmentController;
use App\Controllers\EmailController;
use App\Controllers\HealthController;
use App\Middlewares\ErrorHandlerMiddleware;
use App\Repositories\AttachmentRepository;
use App\Repositories\EmailRepository;
use App\Services\ClassificationService;
use App\Services\ContactCleaningService;
use App\Services\ContactExtractionService;
use App\Services\EmailService;
use App\Services\ListmonkSyncService;
use App\Services\PilerDumpDataSource;
use App\Services\ResponseHelper;

$f3 = Base::instance();

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

$contactExtractionService = new ContactExtractionService();
$contactCleaningService = new ContactCleaningService();
$classifier = new ClassificationService();
$listmonkSyncService = new ListmonkSyncService();

$emailService = new EmailService(
    $emailRepository,
    $attachmentRepository,
    $contactExtractionService,
    $contactCleaningService,
    $classifier,
    $listmonkSyncService
);

$healthController = new HealthController();
$emailController = new EmailController($emailService);
$attachmentController = new AttachmentController($emailService);

$f3->set('ONERROR', [ErrorHandlerMiddleware::class, 'handle']);

$f3->route('GET /', static function (Base $f3): void {
    ResponseHelper::success($f3, [
        'message' => 'Welcome to Piler Archive Extractor API',
        'version' => 'mvp',
    ]);
});

$f3->route('GET /gui', static function (Base $f3) use ($projectRoot): void {
    $guiFile = $projectRoot . '/public/gui/index.html';
    if (!is_file($guiFile)) {
        ResponseHelper::error($f3, 'GUI file not found', 404);
        return;
    }

    $f3->set('HEADERS.Content-Type', 'text/html; charset=utf-8');
    echo file_get_contents($guiFile);
});

$f3->route('GET /health', [$healthController, 'index']);
$f3->route('GET /emails', [$emailController, 'index']);
$f3->route('GET /emails/search', [$emailController, 'search']);
$f3->route('GET /emails/@id', [$emailController, 'show']);
$f3->route('GET /emails/@id/attachments', [$attachmentController, 'index']);
