<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\CompileCampaignMessageAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * #7: UTM tagging and click tracking must not HTML-escape the destination URL
 * twice, or every parameter after the first arrives as "amp;name".
 */
class TrackedLinkDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_following_a_tracked_utm_link_lands_on_the_exact_destination(): void
    {
        $recipient = $this->recipientFor('<p><a href="https://shop.example.com/sale?ref=news&amp;id=5#top">Shop</a></p>', utmAutoTag: true);

        $this->followTrackedLink($recipient)->assertRedirect(
            'https://shop.example.com/sale?ref=news&id=5&utm_source=focal&utm_medium=email&utm_campaign=spring-sale#top'
        );
    }

    public function test_a_raw_ampersand_in_the_template_survives_tagging_and_tracking(): void
    {
        $recipient = $this->recipientFor('<p><a href="https://shop.example.com/sale?a=1&b=two%20words">Shop</a></p>', utmAutoTag: true);

        $this->followTrackedLink($recipient)->assertRedirect(
            'https://shop.example.com/sale?a=1&b=two%20words&utm_source=focal&utm_medium=email&utm_campaign=spring-sale'
        );
    }

    public function test_tracked_links_without_utm_tagging_keep_their_query_string(): void
    {
        $recipient = $this->recipientFor('<p><a href="https://shop.example.com/sale?ref=news&amp;id=5">Shop</a></p>', utmAutoTag: false);

        $this->followTrackedLink($recipient)->assertRedirect('https://shop.example.com/sale?ref=news&id=5');
    }

    private function followTrackedLink(CampaignRecipient $recipient): TestResponse
    {
        $html = app(CompileCampaignMessageAction::class)->execute($recipient->campaign, $recipient);

        $this->assertSame(1, preg_match('/<a\s[^>]*href="([^"]+)"/', $html, $matches), 'The compiled message should contain a link.');
        $this->assertStringNotContainsString('&amp;amp;', $html);

        // A mail client decodes the attribute value, then requests it.
        $trackedUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('/marketing/track/click/'.$recipient->tracking_token, $trackedUrl);

        return $this->get($trackedUrl);
    }

    private function recipientFor(string $bodyHtml, bool $utmAutoTag): CampaignRecipient
    {
        $template = MarketingTemplate::create(['name' => 'Sale', 'subject' => 'Sale', 'body_html' => $bodyHtml]);

        $campaign = Campaign::create([
            'name' => 'Spring Sale',
            'subject' => 'Sale',
            'sender_name' => 'Focal',
            'sender_email' => 'news@example.com',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
            'utm_auto_tag' => $utmAutoTag,
        ]);

        $contact = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);

        return CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => 'ada@example.com',
            'status' => RecipientStatus::Sent,
        ]);
    }
}
