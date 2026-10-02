<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Marketing\Models\Campaign;

class AuditCampaignDeliverabilityAction
{
    /**
     * Known spam trigger words and phrases.
     *
     * @var list<string>
     */
    protected const SPAM_TRIGGER_WORDS = [
        '100% free',
        'free money',
        'risk free',
        'risk-free',
        'act now',
        'urgent',
        'congratulations',
        'winner',
        'cash bonus',
        'double your income',
        'fast cash',
        'no obligation',
        'guaranteed',
        'miracle',
    ];

    /**
     * Common consumer domains unsuitable for bulk business broadcasting.
     *
     * @var list<string>
     */
    protected const CONSUMER_DOMAINS = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'aol.com',
        'icloud.com',
    ];

    /**
     * Run a comprehensive pre-flight deliverability and spam analysis on a campaign.
     *
     * @return array{
     *     score: int,
     *     rating: string,
     *     checks: list<array{name: string, passed: bool, severity: string, message: string}>
     * }
     */
    public function execute(Campaign $campaign): array
    {
        $score = 100;
        $checks = [];

        $template = $campaign->template;
        $body = $template !== null ? $template->body_html : '';
        $subject = (string) $campaign->subject;
        $senderEmail = strtolower(trim((string) $campaign->sender_email));

        // 1. Unsubscribe Compliance Check
        $hasUnsubscribe = str_contains($body, '{{unsubscribe_url}}') || str_contains($body, 'unsubscribe');
        if ($hasUnsubscribe) {
            $checks[] = [
                'name' => 'Unsubscribe Link Compliance',
                'passed' => true,
                'severity' => 'success',
                'message' => 'Unsubscribe placeholder is present and compliant with CAN-SPAM & GDPR.',
            ];
        } else {
            $score -= 25;
            $checks[] = [
                'name' => 'Unsubscribe Link Compliance',
                'passed' => false,
                'severity' => 'danger',
                'message' => 'Missing {{unsubscribe_url}} tag. Campaigns without opt-out links will be rejected by mailbox providers.',
            ];
        }

        // 2. Merge Tag Syntax & Integrity Check
        $hasBrokenTags = false;
        if (preg_match('/\{\{[^\}]*$/m', $body) || (substr_count($body, '{{') !== substr_count($body, '}}'))) {
            $hasBrokenTags = true;
        }

        if (! $hasBrokenTags) {
            $checks[] = [
                'name' => 'Merge Tag Syntax',
                'passed' => true,
                'severity' => 'success',
                'message' => 'All personalization merge tags are balanced and correctly formatted.',
            ];
        } else {
            $score -= 20;
            $checks[] = [
                'name' => 'Merge Tag Syntax',
                'passed' => false,
                'severity' => 'danger',
                'message' => 'Unclosed or malformed merge tag detected (e.g. unclosed {{).',
            ];
        }

        // 3. Subject Line & Spam Trigger Words
        $spamWordFound = null;
        $lowerSubject = strtolower($subject);
        foreach (self::SPAM_TRIGGER_WORDS as $spamWord) {
            if (str_contains($lowerSubject, $spamWord) || str_contains(strtolower($body), $spamWord)) {
                $spamWordFound = $spamWord;
                break;
            }
        }

        $excessiveCaps = strlen($subject) > 8 && (preg_match('/[A-Z\s]{8,}/', $subject) === 1);
        $excessivePunctuation = (preg_match('/[!$?]{2,}/', $subject) === 1);

        if ($spamWordFound !== null || $excessiveCaps || $excessivePunctuation) {
            $penalty = 15;
            $score -= $penalty;
            $msg = $spamWordFound !== null
                ? "Flagged spam trigger phrase detected: \"{$spamWordFound}\"."
                : ($excessiveCaps ? 'Subject line contains excessive capitalization.' : 'Subject contains repetitive punctuation (e.g. "!!!").');

            $checks[] = [
                'name' => 'Spam Keyword & Subject Hygiene',
                'passed' => false,
                'severity' => 'warning',
                'message' => $msg,
            ];
        } else {
            $checks[] = [
                'name' => 'Spam Keyword & Subject Hygiene',
                'passed' => true,
                'severity' => 'success',
                'message' => 'Subject line and copy are free from aggressive promotional spam triggers.',
            ];
        }

        // 4. Sender Domain DMARC & Authentication Readiness
        $domain = str_contains($senderEmail, '@') ? explode('@', $senderEmail)[1] : '';
        if (in_array($domain, self::CONSUMER_DOMAINS, true)) {
            $score -= 20;
            $checks[] = [
                'name' => 'Sender Domain Authentication',
                'passed' => false,
                'severity' => 'danger',
                'message' => "Sender domain (@{$domain}) is a consumer inbox. Gmail/Yahoo DMARC policies will reject bulk sends.",
            ];
        } else {
            $checks[] = [
                'name' => 'Sender Domain Authentication',
                'passed' => true,
                'severity' => 'success',
                'message' => "Using dedicated corporate domain (@{$domain}).",
            ];
        }

        // 5. Content Volume & Body Length
        if (strlen(strip_tags($body)) < 30) {
            $score -= 10;
            $checks[] = [
                'name' => 'Content Volume',
                'passed' => false,
                'severity' => 'warning',
                'message' => 'Email copy is very short. Sparse emails have a higher chance of triggering automated image-only spam filters.',
            ];
        } else {
            $checks[] = [
                'name' => 'Content Volume',
                'passed' => true,
                'severity' => 'success',
                'message' => 'Body text volume is optimal for deliverability.',
            ];
        }

        $finalScore = max(0, min(100, $score));

        $rating = match (true) {
            $finalScore >= 90 => 'Excellent',
            $finalScore >= 75 => 'Good',
            $finalScore >= 50 => 'Fair (Needs Review)',
            default => 'High Spam Risk',
        };

        return [
            'score' => $finalScore,
            'rating' => $rating,
            'checks' => $checks,
        ];
    }
}
