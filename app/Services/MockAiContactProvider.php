<?php

declare(strict_types=1);

namespace App\Services;

final class MockAiContactProvider implements AiContactProviderInterface
{
    public function getName(): string
    {
        return 'mock';
    }

    public function analyzeContacts(array $contacts, array $context = []): array
    {
        $sourceCounts = is_array($context['source_counts'] ?? null) ? $context['source_counts'] : [];
        $results = [];

        foreach ($contacts as $contact) {
            $email = mb_strtolower(trim((string) $contact));
            if ($email === '') {
                continue;
            }

            $sourceCount = max(1, (int) ($sourceCounts[$email] ?? 1));
            $results[] = $this->analyzeContact($email, $sourceCount);
        }

        return $results;
    }

    private function analyzeContact(string $email, int $sourceCount): array
    {
        $domain = $this->domainFromEmail($email);
        $localPart = explode('@', $email)[0] ?? '';
        $category = 'business';
        $segment = 'Business/general';
        $confidence = 0.62;
        $reason = 'Business-looking contact with no stronger category signal.';

        if ($this->isSystemNoise($email)) {
            $category = 'system_noise';
            $segment = 'System noise';
            $confidence = 0.96;
            $reason = 'Address matches a known automated mailbox pattern.';
        } elseif (str_ends_with($domain, '.edu')) {
            $category = 'education';
            $segment = 'Education contacts';
            $confidence = 0.9;
            $reason = 'Domain ends with .edu.';
        } elseif (str_contains($domain, 'gov')) {
            $category = 'government';
            $segment = 'Government/public sector';
            $confidence = 0.88;
            $reason = 'Domain contains a government signal.';
        } elseif ($this->hasTechnicalSignal($localPart)) {
            $category = 'technical';
            $segment = 'Technical and support contacts';
            $confidence = 0.82;
            $reason = 'Mailbox name suggests a technical/support role.';
        } elseif ($this->hasMarketingSignal($localPart, $domain)) {
            $category = 'marketing';
            $segment = 'Marketing and growth contacts';
            $confidence = 0.8;
            $reason = 'Address contains a marketing or campaign signal.';
        } elseif (str_ends_with($domain, '.tn')) {
            $category = 'business';
            $segment = 'Local business';
            $confidence = 0.76;
            $reason = 'Local .tn domain with no stronger category signal.';
        }

        return [
            'email' => $email,
            'domain' => $domain,
            'category' => $category,
            'segment' => $segment,
            'lead_score' => $this->leadScore($email, $domain, $category, $sourceCount),
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }

    private function leadScore(string $email, string $domain, string $category, int $sourceCount): int
    {
        if ($category === 'system_noise') {
            return min(15, 4 + min(10, $sourceCount));
        }

        $score = 38;
        $score += min(28, $sourceCount * 4);

        if ($this->isBusinessLookingDomain($domain)) {
            $score += 18;
        }

        if (in_array($category, ['technical', 'marketing', 'government', 'education'], true)) {
            $score += 8;
        }

        if (str_ends_with($domain, '.tn')) {
            $score += 6;
        }

        if ($this->isFreeMailboxDomain($domain)) {
            $score -= 12;
        }

        if ($this->isSystemNoise($email)) {
            $score -= 35;
        }

        return max(0, min(100, $score));
    }

    private function domainFromEmail(string $email): string
    {
        return explode('@', $email)[1] ?? '';
    }

    private function isSystemNoise(string $email): bool
    {
        return (bool) preg_match('/(^|[._%+\-@])(no-?reply|postmaster|mailer-daemon)([._%+\-@]|$)/i', $email);
    }

    private function hasTechnicalSignal(string $localPart): bool
    {
        return (bool) preg_match('/(^|[._%+\-])(devops|support|admin|tech|it)([._%+\-]|$)/i', $localPart);
    }

    private function hasMarketingSignal(string $localPart, string $domain): bool
    {
        return (bool) preg_match('/(^|[._%+\-])(marketing|sales|growth|campaign|crm)([._%+\-]|$)/i', $localPart)
            || str_contains($domain, 'campaign')
            || str_contains($domain, 'marketing');
    }

    private function isBusinessLookingDomain(string $domain): bool
    {
        return $domain !== ''
            && !$this->isFreeMailboxDomain($domain)
            && !str_contains($domain, 'localhost')
            && str_contains($domain, '.');
    }

    private function isFreeMailboxDomain(string $domain): bool
    {
        return in_array($domain, [
            'gmail.com',
            'hotmail.com',
            'outlook.com',
            'yahoo.com',
            'icloud.com',
            'aol.com',
        ], true);
    }
}
