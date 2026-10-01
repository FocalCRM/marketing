<?php

declare(strict_types=1);

namespace Focal\Marketing\Services;

class DomainThrottler
{
    /**
     * Standard ISP dispatch rate limits (recipients per minute).
     *
     * @var array<string, int>
     */
    public const DEFAULT_ISP_LIMITS = [
        'yahoo.com' => 60,
        'ymail.com' => 60,
        'aol.com' => 60,
        'gmail.com' => 120,
        'googlemail.com' => 120,
        'hotmail.com' => 100,
        'outlook.com' => 100,
        'live.com' => 100,
        'msn.com' => 100,
        'icloud.com' => 80,
        'me.com' => 80,
    ];

    /**
     * Extract the domain part from an email address.
     */
    public static function extractDomain(string $email): string
    {
        $email = trim($email);
        if (! str_contains($email, '@')) {
            return 'unknown';
        }

        $parts = explode('@', $email);

        return strtolower(trim($parts[1] ?? 'unknown'));
    }

    /**
     * Group a list of recipient items by their email domain.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return array<string, list<array<string, mixed>>>
     */
    public static function groupRecipientsByDomain(array $recipients, string $emailKey = 'to'): array
    {
        $grouped = [];

        foreach ($recipients as $recipient) {
            $email = isset($recipient[$emailKey]) && is_string($recipient[$emailKey])
                ? $recipient[$emailKey]
                : '';

            $domain = self::extractDomain($email);
            if (! isset($grouped[$domain])) {
                $grouped[$domain] = [];
            }
            $grouped[$domain][] = $recipient;
        }

        return $grouped;
    }

    /**
     * Partition recipients into time-bucketed dispatch waves based on domain rate limits.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @param  array<string, int>  $customDomainLimits
     * @return array{
     *     total_recipients: int,
     *     domain_distribution: array<string, int>,
     *     waves: list<array{
     *         wave_index: int,
     *         offset_seconds: int,
     *         count: int,
     *         recipients: list<array<string, mixed>>
     *     }>,
     *     estimated_dispatch_duration_seconds: int
     * }
     */
    public static function calculateThrottledBatches(
        array $recipients,
        array $customDomainLimits = [],
        int $defaultPerMinute = 120,
        string $emailKey = 'to'
    ): array {
        $limits = array_merge(self::DEFAULT_ISP_LIMITS, $customDomainLimits);
        $grouped = self::groupRecipientsByDomain($recipients, $emailKey);

        $domainDistribution = [];
        foreach ($grouped as $domain => $domainRecipients) {
            $domainDistribution[$domain] = count($domainRecipients);
        }

        /** @var array<int, list<array<string, mixed>>> $waveBuckets */
        $waveBuckets = [];

        foreach ($grouped as $domain => $domainRecipients) {
            $limit = $limits[$domain] ?? $defaultPerMinute;
            $chunks = array_chunk($domainRecipients, max(1, $limit));

            foreach ($chunks as $waveIndex => $chunk) {
                if (! isset($waveBuckets[$waveIndex])) {
                    $waveBuckets[$waveIndex] = [];
                }
                foreach ($chunk as $rec) {
                    $waveBuckets[$waveIndex][] = $rec;
                }
            }
        }

        ksort($waveBuckets);

        $waves = [];
        foreach ($waveBuckets as $waveIndex => $items) {
            $waves[] = [
                'wave_index' => $waveIndex,
                'offset_seconds' => $waveIndex * 60,
                'count' => count($items),
                'recipients' => $items,
            ];
        }

        $maxWave = count($waves);
        $durationSeconds = $maxWave > 0 ? ($maxWave - 1) * 60 : 0;

        return [
            'total_recipients' => count($recipients),
            'domain_distribution' => $domainDistribution,
            'waves' => $waves,
            'estimated_dispatch_duration_seconds' => $durationSeconds,
        ];
    }
}
