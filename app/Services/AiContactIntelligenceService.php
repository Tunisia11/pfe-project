<?php

declare(strict_types=1);

namespace App\Services;

final class AiContactIntelligenceService
{
    public function __construct(
        private readonly ClassificationService $classificationService,
        private readonly AiContactProviderInterface $provider,
        private readonly bool $enabled = false,
        private readonly string $providerName = 'mock',
        private readonly int $batchSize = 50
    ) {
    }

    /**
     * @param array<int, string> $contacts
     * @param array{source_counts?: array<string, int>} $context
     *
     * @return array{
     *   enabled: bool,
     *   provider: string,
     *   mode: string,
     *   notice: string,
     *   batch_size: int,
     *   contacts: array<int, array<string, mixed>>,
     *   stats: array<string, mixed>
     * }
     */
    public function analyzeContacts(array $contacts, array $context = []): array
    {
        $normalizedContacts = $this->normalizeContacts($contacts);

        if ($this->enabled === false) {
            $enriched = $this->fallbackRuleBasedContacts($normalizedContacts);

            return [
                'enabled' => false,
                'provider' => 'rule_based',
                'mode' => 'disabled',
                'notice' => 'AI enrichment is disabled. Using rule-based classification.',
                'batch_size' => $this->batchSize,
                'contacts' => $enriched,
                'stats' => $this->buildStats($enriched),
            ];
        }

        $enriched = [];
        foreach (array_chunk($normalizedContacts, max(1, $this->batchSize)) as $batch) {
            $enriched = array_merge($enriched, $this->provider->analyzeContacts($batch, $context));
        }

        return [
            'enabled' => true,
            'provider' => $this->providerName,
            'mode' => $this->providerName === 'mock' ? 'demo_mock' : 'provider',
            'notice' => $this->providerName === 'mock'
                ? 'Demo AI mode: mock provider.'
                : sprintf('AI enrichment enabled with provider: %s.', $this->providerName),
            'batch_size' => $this->batchSize,
            'contacts' => $enriched,
            'stats' => $this->buildStats($enriched),
        ];
    }

    private function normalizeContacts(array $contacts): array
    {
        $normalized = [];

        foreach ($contacts as $contact) {
            $email = mb_strtolower(trim((string) $contact));
            if ($email !== '') {
                $normalized[] = $email;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, string> $contacts
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackRuleBasedContacts(array $contacts): array
    {
        $legacyClassifications = $this->classificationService->classifyContacts($contacts);
        $enriched = [];

        foreach ($legacyClassifications as $classification) {
            $email = (string) ($classification['email'] ?? '');
            $legacyCategory = (string) ($classification['category'] ?? 'business-or-general');
            $category = $this->mapLegacyCategory($legacyCategory);

            $enriched[] = [
                'email' => $email,
                'domain' => explode('@', $email)[1] ?? '',
                'category' => $category,
                'segment' => $this->segmentForCategory($category),
                'lead_score' => $category === 'business' ? 52 : 58,
                'confidence' => 0.5,
                'reason' => 'AI enrichment disabled; legacy rule-based classification used.',
            ];
        }

        return $enriched;
    }

    private function mapLegacyCategory(string $category): string
    {
        return match ($category) {
            'education-or-local-domain' => 'education',
            'public-sector' => 'government',
            default => 'business',
        };
    }

    private function segmentForCategory(string $category): string
    {
        return match ($category) {
            'education' => 'Education contacts',
            'government' => 'Government/public sector',
            'technical' => 'Technical and support contacts',
            'marketing' => 'Marketing and growth contacts',
            'internal' => 'Internal contacts',
            'system_noise' => 'System noise',
            default => 'Business/general',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     *
     * @return array<string, mixed>
     */
    private function buildStats(array $contacts): array
    {
        $categories = [];
        $segments = [];
        $highValueContacts = 0;
        $lowConfidenceContacts = 0;

        foreach ($contacts as $contact) {
            $category = (string) ($contact['category'] ?? 'unknown');
            $segment = (string) ($contact['segment'] ?? 'Unknown');
            $leadScore = (int) ($contact['lead_score'] ?? 0);
            $confidence = (float) ($contact['confidence'] ?? 0.0);

            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $segments[$segment] = ($segments[$segment] ?? 0) + 1;

            if ($leadScore >= 70 && $category !== 'system_noise') {
                $highValueContacts++;
            }

            if ($confidence < 0.6) {
                $lowConfidenceContacts++;
            }
        }

        ksort($categories);
        ksort($segments);

        return [
            'contacts_analyzed' => count($contacts),
            'high_value_contacts' => $highValueContacts,
            'low_confidence_contacts' => $lowConfidenceContacts,
            'categories_count' => $categories,
            'segments_count' => $segments,
        ];
    }
}
