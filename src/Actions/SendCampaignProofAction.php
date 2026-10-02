<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use DoPHP\MailBuilder\MailBuilder;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Mail\CampaignProofMailable;
use Focal\Marketing\Models\Campaign;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendCampaignProofAction
{
    /**
     * Send a proof / test email for a campaign to internal reviewers.
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
            '{{unsubscribe_url}}' => route('focal.marketing.unsubscribe.show', 'sample-proof-token'),
            '{{campaign.subject}}' => $campaign->subject,
            '{{campaign.name}}' => $campaign->name,
        ];

        // Values are HTML-escaped: the sample contact's fields are untrusted input.
        $html = str_replace(array_keys($placeholders), array_map(e(...), $placeholders), $rawHtml);

        // Evaluate smart dynamic content blocks if present
        $html = app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);

        $subject = '[TEST] '.($campaign->subject ?: $campaign->name);
        $fromName = $campaign->sender_name ?: (string) config('focal-marketing.defaults.sender_name', 'Focal Marketing');
        $fromEmail = $campaign->sender_email ?: (string) config('focal-marketing.defaults.sender_email', 'newsletter@focal.test');
        $replyTo = $campaign->reply_to_email ?: (string) config('focal-marketing.defaults.reply_to', 'support@focal.test');

        try {
            foreach ($cleanEmails as $recipientEmail) {
                Mail::to($recipientEmail)->send(new CampaignProofMailable(
                    subjectLine: $subject,
                    htmlBody: $html,
                    fromEmail: $fromEmail,
                    fromName: $fromName,
                    replyToEmail: $replyTo !== '' ? $replyTo : null,
                ));
            }

            return [
                'success' => true,
                'sent_to' => $cleanEmails,
                'message' => 'Proof email successfully dispatched to '.implode(', ', $cleanEmails),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'sent_to' => [],
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ];
        }
    }
}
