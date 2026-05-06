<?php

declare(strict_types=1);

use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\EmailController;
use App\Controllers\HealthController;
use App\Database\AppDatabase;
use App\Middlewares\ErrorHandlerMiddleware;
use App\Middlewares\AuthMiddleware;
use App\Repositories\AdminSessionRepository;
use App\Repositories\AdminUserRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\ContactSourceRepository;
use App\Repositories\EmailRepository;
use App\Repositories\ExtractedContactRepository;
use App\Repositories\SyncRunRepository;
use App\Services\AuditLogService;
use App\Services\AiContactIntelligenceService;
use App\Services\AuthService;
use App\Services\ClassificationService;
use App\Services\ContactCleaningService;
use App\Services\ContactExtractionService;
use App\Services\EmailService;
use App\Services\ListmonkSyncService;
use App\Services\MockAiContactProvider;
use App\Services\PilerDumpDataSource;
use App\Services\ResponseHelper;

$f3 = Base::instance();

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$appDatabase = new AppDatabase(($f3->get('app')['app_db'] ?? []));
$appPdo = $appDatabase->pdo();
$adminUserRepository = new AdminUserRepository($appPdo);
$adminSessionRepository = new AdminSessionRepository($appPdo);
$extractedContactRepository = new ExtractedContactRepository($appPdo);
$contactSourceRepository = new ContactSourceRepository($appPdo);
$syncRunRepository = new SyncRunRepository($appPdo);
$auditLogService = new AuditLogService($appPdo);

$sessionConfig = $f3->get('app')['session'] ?? [];
$authService = new AuthService(
    $adminUserRepository,
    $adminSessionRepository,
    $auditLogService,
    (string) ($sessionConfig['name'] ?? 'piler_admin_session'),
    (int) ($sessionConfig['lifetime_minutes'] ?? 120)
);
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
$aiConfig = $f3->get('app')['ai'] ?? [];
$aiProviderName = (string) ($aiConfig['provider'] ?? 'mock');
$aiProvider = new MockAiContactProvider();
$aiContactIntelligenceService = new AiContactIntelligenceService(
    $classifier,
    $aiProvider,
    (bool) ($aiConfig['enabled'] ?? false),
    $aiProviderName,
    (int) ($aiConfig['batch_size'] ?? 50)
);
$listmonkSyncService = new ListmonkSyncService();

$emailService = new EmailService(
    $emailRepository,
    $attachmentRepository,
    $contactExtractionService,
    $contactCleaningService,
    $classifier,
    $aiContactIntelligenceService,
    $listmonkSyncService,
    $extractedContactRepository,
    $contactSourceRepository,
    $syncRunRepository
);

$authController = new AuthController($authService, $projectRoot);
$healthController = new HealthController();
$emailController = new EmailController($emailService);
$attachmentController = new AttachmentController($emailService);
$contactController = new ContactController(
    $extractedContactRepository,
    $contactSourceRepository,
    $syncRunRepository,
    $emailService,
    $auditLogService
);

$f3->set('ONERROR', [ErrorHandlerMiddleware::class, 'handle']);

$f3->route('GET /', static function (Base $f3): void {
    ResponseHelper::success($f3, [
        'message' => 'Welcome to Piler Archive Extractor API',
        'version' => 'mvp',
    ]);
});

$f3->route('GET /login', [$authController, 'loginPage']);
$f3->route('POST /auth/login', [$authController, 'login']);
$f3->route('POST /auth/logout', [$authController, 'logout']);
$f3->route('GET /auth/me', [$authController, 'me']);

$f3->route('GET /gui', static function (Base $f3) use ($projectRoot, $authService): void {
    if (!AuthMiddleware::requireAuth($f3, $authService, true)) {
        return;
    }

    $guiFile = $projectRoot . '/app/Views/gui.html';
    if (!is_file($guiFile)) {
        ResponseHelper::error($f3, 'GUI file not found', 404);
        return;
    }

    $f3->set('HEADERS.Content-Type', 'text/html; charset=utf-8');
    echo file_get_contents($guiFile);
});

$f3->route('GET /health', [$healthController, 'index']);
$f3->route('GET /ai/status', static function (Base $f3) use ($authService): void {
    if (!AuthMiddleware::requireAuth($f3, $authService)) {
        return;
    }

    $ai = $f3->get('app')['ai'] ?? [];
    $enabled = (bool) ($ai['enabled'] ?? false);
    $provider = (string) ($ai['provider'] ?? 'mock');

    ResponseHelper::success($f3, [
        'data' => [
            'enabled' => $enabled,
            'provider' => $enabled ? $provider : 'rule_based',
            'mode' => $enabled && $provider === 'mock' ? 'demo_mock' : ($enabled ? 'provider' : 'disabled'),
            'notice' => $enabled && $provider === 'mock'
                ? 'Demo AI mode: mock provider.'
                : ($enabled ? sprintf('AI enrichment enabled with provider: %s.', $provider) : 'AI enrichment is disabled. Using rule-based classification.'),
            'batch_size' => max(1, (int) ($ai['batch_size'] ?? 50)),
        ],
    ]);
});
$f3->route('POST /contacts/intelligence', static function (Base $f3) use ($aiContactIntelligenceService, $authService): void {
    if (!AuthMiddleware::requireAuth($f3, $authService)) {
        return;
    }

    $rawBody = file_get_contents('php://input');
    $payload = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : [];

    if (!is_array($payload)) {
        ResponseHelper::error($f3, 'Invalid JSON payload', 400);
        return;
    }

    $contacts = $payload['contacts'] ?? [];
    if (!is_array($contacts)) {
        ResponseHelper::error($f3, 'contacts must be an array', 422);
        return;
    }

    $normalizedContacts = [];
    foreach ($contacts as $contact) {
        $email = mb_strtolower(trim((string) $contact));
        if ($email !== '') {
            $normalizedContacts[] = $email;
        }
    }

    $normalizedContacts = array_values(array_unique($normalizedContacts));
    if (count($normalizedContacts) > 5000) {
        ResponseHelper::error($f3, 'Too many contacts for one intelligence request. Maximum is 5000.', 422);
        return;
    }

    $sourceCounts = [];
    if (is_array($payload['source_counts'] ?? null)) {
        foreach ($payload['source_counts'] as $email => $count) {
            $sourceCounts[mb_strtolower(trim((string) $email))] = max(1, (int) $count);
        }
    }

    ResponseHelper::success($f3, [
        'data' => $aiContactIntelligenceService->analyzeContacts($normalizedContacts, [
            'source_counts' => $sourceCounts,
        ]),
    ]);
});
$f3->route('GET /emails', static function (Base $f3) use ($authService, $emailController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $emailController->index($f3);
    }
});
$f3->route('GET /emails/search', static function (Base $f3) use ($authService, $emailController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $emailController->search($f3);
    }
});
$f3->route('GET /emails/@id', static function (Base $f3, array $params) use ($authService, $emailController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $emailController->show($f3, $params);
    }
});
$f3->route('GET /emails/@id/attachments', static function (Base $f3, array $params) use ($authService, $attachmentController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $attachmentController->index($f3, $params);
    }
});

$f3->route('GET /contacts', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->index($f3);
    }
});
$f3->route('GET /contacts/stats', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->stats($f3);
    }
});
$f3->route('GET /contacts/@id/sources', static function (Base $f3, array $params) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->sources($f3, $params);
    }
});
$f3->route('GET /contacts/export.csv', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->exportCsv($f3);
    }
});
$f3->route('POST /contacts/extract', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->extract($f3);
    }
});
$f3->route('GET /contacts/extract', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->extract($f3);
    }
});
$f3->route('PATCH /contacts/@id/status', static function (Base $f3, array $params) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->updateStatus($f3, $params);
    }
});
$f3->route('GET /sync-runs', static function (Base $f3) use ($authService, $contactController): void {
    if (AuthMiddleware::requireAuth($f3, $authService)) {
        $contactController->syncRuns($f3);
    }
});
