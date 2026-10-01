<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Marketing\Models\Campaign;

class LintCampaignDeliverabilityAction
{
    /**
     * Common freemail domains that enforce DMARC p=reject on custom bulk mailings.
     *
     * @var list<string>
     */
    protected const FREEMAIL_DOMAINS = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'live.com',
        'icloud.com',
        'aol.com',
        'mail.com',
        'proton.me',
        'protonmail.com',
    ];

    /**
     * Spam trigger keywords/phrases to flag in subject lines.
     *
     * @var list<string>
     */
    protected const SPAM_KEYWORDS = [
        '100% free',
        'buy now',
        'act now',
        'claim your',
        'risk-free',
        'make money',
        'earn cash',
        'instant cash',
        'wire transfer',
        'congratulations you won',
        'million dollars',
        'no catch',
    ];

    /**
     * Run pre-flight deliverability and spam compliance checks on a campaign.
     *
     * @return array{
     *     score: int,
     *     status: 'excellent'|'good'|'warning'|'critical',
     *     passed_checks: list<string>,
     *     warnings: list<array{rule: string, message: string, severity: 'warning'|'critical'}>,
     *     recommendations: list<string>
     * }
     */
    public function execute(
        Campaign $campaign,
        ?string $overrideHtml = null,
        ?string $overrideSubject = null
    ): array {
        $subject = trim($overrideSubject ?? $campaign->subject);
        $previewText = trim((string) ($campaign->preview_text ?? ''));
        $senderEmail = trim($campaign->sender_email);
        $bodyHtml = $overrideHtml ?? ($campaign->template->body_html ?? '');

        $score = 100;
        /** @var list<string> $passed */
        $passed = [];
        /** @var list<array{rule: string, message: string, severity: 'warning'|'critical'}> $warnings */
        $warnings = [];
        /** @var list<string> $recommendations */
        $recommendations = [];

        // 1. Sender Email & Domain Compliance
        if (empty($senderEmail) || ! filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $score -= 30;
            $warnings[] = [
                'rule' => 'sender_email_valid',
                'message' => 'Sender email address is missing or invalid.',
                'severity' => 'critical',
            ];
            $recommendations[] = 'Provide a valid, authenticated corporate sender email address.';
        } else {
            $senderDomain = mb_strtolower(substr(strrchr($senderEmail, '@') ?: '', 1));
            if (in_array($senderDomain, self::FREEMAIL_DOMAINS, true)) {
                $score -= 25;
                $warnings[] = [
                    'rule' => 'sender_domain_authenticated',
                    'message' => "Sending from public freemail provider (@{$senderDomain}) will be blocked by DMARC p=reject policies.",
                    'severity' => 'critical',
                ];
                $recommendations[] = 'Configure a custom sending domain with SPF, DKIM, and DMARC alignment.';
            } else {
                $passed[] = "Sender domain (@{$senderDomain}) matches authenticated corporate domain standard.";
            }
        }

        // 2. Subject Line Length & Formatting
        $subjectLength = mb_strlen($subject);
        if ($subjectLength === 0) {
            $score -= 30;
            $warnings[] = [
                'rule' => 'subject_required',
                'message' => 'Subject line is blank.',
                'severity' => 'critical',
            ];
            $recommendations[] = 'Add a clear, compelling subject line.';
        } elseif ($subjectLength > 60) {
            $score -= 10;
            $warnings[] = [
                'rule' => 'subject_length',
                'message' => "Subject line length ({$subjectLength} chars) exceeds the 60-character mobile client cutoff.",
                'severity' => 'warning',
            ];
            $recommendations[] = 'Keep subject lines between 20 and 50 characters for peak mobile open rates.';
        } elseif ($subjectLength < 8) {
            $score -= 10;
            $warnings[] = [
                'rule' => 'subject_length',
                'message' => 'Subject line is too short (< 8 characters) and may appear ambiguous.',
                'severity' => 'warning',
            ];
        } else {
            $passed[] = "Subject line length ({$subjectLength} chars) is optimal for desktop and mobile.";
        }

        // 3. Subject Spam Words & Punctuation
        if (preg_match('/(!{2,}|\?{2,}|\${2,})/', $subject)) {
            $score -= 10;
            $warnings[] = [
                'rule' => 'subject_punctuation',
                'message' => 'Subject line contains excessive punctuation (e.g. "!!", "??", "$$").',
                'severity' => 'warning',
            ];
            $recommendations[] = 'Eliminate repeated exclamation marks and dollar symbols from the subject line.';
        } else {
            $passed[] = 'Subject line punctuation is clean and natural.';
        }

        $lowerSubject = mb_strtolower($subject);
        $foundSpamKeywords = [];
        foreach (self::SPAM_KEYWORDS as $keyword) {
            if (str_contains($lowerSubject, $keyword)) {
                $foundSpamKeywords[] = $keyword;
            }
        }
        if (! empty($foundSpamKeywords)) {
            $score -= 15;
            $warnings[] = [
                'rule' => 'subject_spam_words',
                'message' => 'Subject line contains flagged spam trigger keywords: "'.implode('", "', $foundSpamKeywords).'".',
                'severity' => 'warning',
            ];
            $recommendations[] = 'Rephrase promotional phrases to focus on value and relevance rather than urgency or free incentives.';
        } else {
            $passed[] = 'No overt spam keywords detected in subject line.';
        }

        // 4. Preview Text (Preheader)
        if (empty($previewText)) {
            $score -= 10;
            $warnings[] = [
                'rule' => 'preview_text_provided',
                'message' => 'Preview text (preheader) is missing.',
                'severity' => 'warning',
            ];
            $recommendations[] = 'Add a 40–90 character preview text to improve inbox preview engagement.';
        } else {
            $passed[] = 'Preview text is configured to complement the subject line.';
        }

        // 5. Unsubscribe Compliance
        $hasUnsubscribeTag = str_contains($bodyHtml, '{{unsubscribe_url}}')
            || str_contains(mb_strtolower($bodyHtml), 'unsubscribe');

        if (! empty($bodyHtml) && ! $hasUnsubscribeTag) {
            $score -= 30;
            $warnings[] = [
                'rule' => 'unsubscribe_compliance',
                'message' => 'Email body is missing an unsubscribe link (required by CAN-SPAM and GDPR).',
                'severity' => 'critical',
            ];
            $recommendations[] = 'Insert an {{unsubscribe_url}} tag in the footer to ensure CAN-SPAM and GDPR compliance.';
        } elseif (! empty($bodyHtml)) {
            $passed[] = 'Unsubscribe mechanism is present.';
        }

        // 6. Broken / Placeholder Links
        if (! empty($bodyHtml) && (str_contains($bodyHtml, 'href=""') || str_contains($bodyHtml, 'href="#"'))) {
            $score -= 10;
            $warnings[] = [
                'rule' => 'broken_placeholder_links',
                'message' => 'Email contains placeholder or empty links (href="" or href="#").',
                'severity' => 'warning',
            ];
            $recommendations[] = 'Replace placeholder links with destination URLs.';
        } else {
            $passed[] = 'No empty or placeholder link anchors detected.';
        }

        // 7. Image-to-Text Ratio
        if (! empty($bodyHtml)) {
            $hasImages = (bool) preg_match('/<img\b[^>]*>/i', $bodyHtml);
            $plainText = trim(strip_tags($bodyHtml));
            if ($hasImages && mb_strlen($plainText) < 100) {
                $score -= 15;
                $warnings[] = [
                    'rule' => 'text_to_image_ratio',
                    'message' => 'Email is image-heavy with minimal accompanying text (< 100 characters).',
                    'severity' => 'warning',
                ];
                $recommendations[] = 'Add at least 2–3 paragraphs of supportive body copy to balance image assets.';
            } else {
                $passed[] = 'Balanced text-to-image proportion.';
            }
        }

        $finalScore = max(0, min(100, $score));
        $status = match (true) {
            $finalScore >= 90 => 'excellent',
            $finalScore >= 75 => 'good',
            $finalScore >= 50 => 'warning',
            default => 'critical',
        };

        return [
            'score' => $finalScore,
            'status' => $status,
            'passed_checks' => $passed,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
        ];
    }
}
