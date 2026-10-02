<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use DoPHP\MailBuilder\MailBuilder;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;

class CompileCampaignMessageAction
{
    /**
     * Compile HTML email body with merge tags, tracking pixel, and click wrappers.
     */
    public function execute(Campaign $campaign, CampaignRecipient $recipient): string
    {
        $isVariantB = $recipient->variant === 'B';
        $template = ($isVariantB && $campaign->variantBTemplate !== null)
            ? $campaign->variantBTemplate
            : $campaign->template;
        $subject = ($isVariantB && ! empty($campaign->variant_b_subject))
            ? $campaign->variant_b_subject
            : ($template !== null && $isVariantB ? $template->getVariantSubject('B') : $campaign->subject);

        /** @var Contact|null $contact */
        $contact = $recipient->contact ?? ($recipient->contact_id !== null ? Contact::find($recipient->contact_id) : null);

        /** @var Company|null $company */
        $company = $contact !== null ? $contact->companies()->first() : null;

        $recipientContext = [
            'contact' => $contact !== null ? $contact->toArray() : ['email' => $recipient->email],
            'company' => $company !== null ? $company->toArray() : [],
        ];

        $rawHtml = '<p>{{content}}</p>';
        if ($template !== null) {
            $slotsToUse = ($isVariantB && ! empty($template->slots_variant_b)) ? $template->slots_variant_b : $template->slots;
            if (! empty($slotsToUse) && class_exists(MailBuilder::class)) {
                $rawHtml = MailBuilder::compile($slotsToUse, [
                    'subject' => $subject,
                    'preview_text' => $isVariantB ? $template->preview_text_variant_b : $template->preview_text,
                    'theme' => $template->theme ?? [],
                    'context' => $recipientContext,
                ]);
            } else {
                $rawHtml = ($template->hasAbTest() && $campaign->variantBTemplate === null)
                    ? $template->getVariantHtml($recipient->variant ?? 'A')
                    : $template->body_html;
            }
        }

        $unsubscribeUrl = $recipient->getUnsubscribeUrl();
        $trackingPixelUrl = $recipient->getTrackingPixelUrl();

        // 1. Merge tags
        $placeholders = [
            '{{contact.first_name}}' => $contact->first_name ?? 'there',
            '{{contact.last_name}}' => $contact->last_name ?? '',
            '{{contact.email}}' => $recipient->email,
            '{{company.name}}' => $company->name ?? 'your organization',
            '{{unsubscribe_url}}' => $unsubscribeUrl,
            '{{campaign.subject}}' => $campaign->subject,
            '{{campaign.name}}' => $campaign->name,
        ];

        // Values are HTML-escaped: contact and company fields are untrusted input.
        $html = str_replace(array_keys($placeholders), array_map(e(...), $placeholders), $rawHtml);

        // Evaluate smart dynamic content blocks
        $html = app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);

        // 2. Append UTM tracking parameters
        $html = app(AppendUtmParametersAction::class)->appendHtmlLinks($html, $campaign, $recipient->variant);

        // 3. Wrap links for click tracking (excluding mailto:, tel:, and unsubscribe)
        $html = (string) preg_replace_callback(
            '/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/i',
            function (array $matches) use ($recipient, $unsubscribeUrl): string {
                $originalUrl = $matches[2];
                if (
                    str_starts_with($originalUrl, 'mailto:') ||
                    str_starts_with($originalUrl, 'tel:') ||
                    str_starts_with($originalUrl, '#') ||
                    $originalUrl === $unsubscribeUrl ||
                    str_contains($originalUrl, CampaignRecipient::unsubscribePathPrefix())
                ) {
                    return $matches[0];
                }

                $trackingUrl = $recipient->getClickRedirectUrl($originalUrl);

                return str_replace($originalUrl, $trackingUrl, $matches[0]);
            },
            $html
        );

        // 3. Inject tracking pixel before </body> or append to end
        $pixelTag = '<img src="'.htmlspecialchars($trackingPixelUrl).'" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;" />';
        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $pixelTag.'</body>', $html);
        } else {
            $html .= $pixelTag;
        }

        return $html;
    }

    /**
     * Compile raw HTML template for a given contact (used by drip workflows).
     */
    public function compileForContact(string $rawHtml, Contact $contact, bool $escape = true): string
    {
        /** @var Company|null $company */
        $company = $contact->companies()->first();

        $placeholders = [
            '{{contact.first_name}}' => $contact->first_name ?? 'there',
            '{{contact.last_name}}' => $contact->last_name ?? '',
            '{{contact.email}}' => $contact->email,
            '{{company.name}}' => $company->name ?? 'your organization',
            '{{unsubscribe_url}}' => $contact->getPreferenceCenterUrl(),
        ];

        // Values are HTML-escaped for HTML bodies: contact and company fields are untrusted input.
        // Plain-text messages such as SMS pass $escape = false.
        $values = $escape ? array_map(e(...), $placeholders) : array_values($placeholders);
        $html = str_replace(array_keys($placeholders), $values, $rawHtml);

        return app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);
    }
}
