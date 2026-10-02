<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\MailBuilder\MailBuilder;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Mail\CampaignProofMailable;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Support\MarketingMailer;
use Throwable;

class SendCampaignProofAction
{
    /**
     * Queue a proof / test email for a campaign to internal reviewers.
     *
     * @param  string|list<string>  $recipientEmails
     * @return array{success: bool, sent_to: list<string>, message: string}
     */
    public function execute(Campaign $campaign, string|array $recipientEmails, ?Contact $sampleContact = null): array
    {
        $emails = is_array($recipientEmails) ? $recipientEmails : explode(',', $recipientEmails);
        $cleanEmails = [];

        foreach ($emails as $email) {
            $e = mb_strtolower(trim($email));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $cleanEmails[] = $e;
            }
        }

        if (empty($cleanEmails)) {
            return [
                'success' => false,
                'sent_to' => [],
                'message' => 'No valid email addresses provided.',
            ];
        }

        $template = $campaign->template;
        $rawHtml = $template !== null ? $template->body_html : '<p>{{content}}</p>';

        if ($template !== null && ! empty($template->slots) && class_exists(MailBuilder::class)) {
            $rawHtml = MailBuilder::compile($template->slots, [
                'preview_text' => $template->preview_text ?? $campaign->preview_text ?? '',
            ]);
        }

        // Resolve sample contact for merge data
        $contact = $sampleContact
            ?? $campaign->crmList?->contacts()->first()
            ?? Contact::query()->first();

        /** @var Company|null $company */
        $company = $contact !== null ? $contact->companies()->first() : null;

        $placeholders = [
            '{{contact.first_name}}' => $contact->first_name ?? 'Jane',
            '{{contact.last_name}}' => $contact->last_name ?? 'Marketer',
            '{{contact.email}}' => $contact->email ?? $cleanEmails[0],
            '{{company.name}}' => $company->name ?? 'Acme Corp',
            '{{unsubscribe_url}}' => route('odden.marketing.unsubscribe.show', 'sample-proof-token'),
            '{{campaign.subject}}' => $campaign->subject,
            '{{campaign.name}}' => $campaign->name,
        ];

        // Values are HTML-escaped: the sample contact's fields are untrusted input.
        $html = str_replace(array_keys($placeholders), array_map(e(...), $placeholders), $rawHtml);

        // Evaluate smart dynamic content blocks if present
        $html = app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);

        $subject = '[TEST] '.($campaign->subject ?: $campaign->name);
        $fromName = $campaign->sender_name ?: (string) config('odden-marketing.defaults.sender_name', 'Odden Marketing');
        $fromEmail = $campaign->sender_email ?: (string) config('odden-marketing.defaults.sender_email', 'newsletter@odden.test');
        $replyTo = $campaign->reply_to_email ?: (string) config('odden-marketing.defaults.reply_to', 'support@odden.test');

        try {
            foreach ($cleanEmails as $recipientEmail) {
                MarketingMailer::queue(new CampaignProofMailable(
                    subjectLine: $subject,
                    htmlBody: $html,
                    fromEmail: $fromEmail,
                    fromName: $fromName,
                    replyToEmail: $replyTo !== '' ? $replyTo : null,
                ), $recipientEmail);
            }

            return [
                'success' => true,
                'sent_to' => $cleanEmails,
                'message' => 'Proof email queued for '.implode(', ', $cleanEmails),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'sent_to' => [],
                'message' => 'Failed to queue test email: '.$e->getMessage(),
            ];
        }
    }
}
