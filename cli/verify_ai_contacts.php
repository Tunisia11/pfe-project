<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AiContactIntelligenceService;
use App\Services\ClassificationService;
use App\Services\MockAiContactProvider;

$contacts = [
    'support@example.com',
    'student@university.edu',
    'info@gov.example',
    'sales@company.tn',
    'noreply@system.tn',
];

$sourceCounts = [
    'support@example.com' => 6,
    'student@university.edu' => 2,
    'info@gov.example' => 3,
    'sales@company.tn' => 5,
    'noreply@system.tn' => 12,
];

$disabledService = new AiContactIntelligenceService(
    new ClassificationService(),
    new MockAiContactProvider(),
    false,
    'mock',
    2
);

$mockService = new AiContactIntelligenceService(
    new ClassificationService(),
    new MockAiContactProvider(),
    true,
    'mock',
    2
);

$disabledResult = $disabledService->analyzeContacts($contacts, ['source_counts' => $sourceCounts]);
$mockResult = $mockService->analyzeContacts($contacts, ['source_counts' => $sourceCounts]);

$assertions = [
    'disabled mode reports disabled' => $disabledResult['enabled'] === false && $disabledResult['mode'] === 'disabled',
    'disabled mode still returns enriched-shaped contacts' => count($disabledResult['contacts']) === count($contacts),
    'mock mode reports enabled demo mode' => $mockResult['enabled'] === true && $mockResult['mode'] === 'demo_mock',
    'mock mode returns analyzed contacts' => count($mockResult['contacts']) === count($contacts),
    'mock mode identifies system noise' => in_array('system_noise', array_column($mockResult['contacts'], 'category'), true),
    'mock stats include analyzed count' => $mockResult['stats']['contacts_analyzed'] === count($contacts),
];

$failed = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));

echo json_encode([
    'success' => $failed === [],
    'failed' => $failed,
    'disabled' => [
        'status' => $disabledResult['notice'],
        'stats' => $disabledResult['stats'],
    ],
    'mock' => [
        'status' => $mockResult['notice'],
        'stats' => $mockResult['stats'],
        'sample' => array_slice($mockResult['contacts'], 0, 3),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
