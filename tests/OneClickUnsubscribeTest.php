<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * #6: RFC 8058 one-click unsubscribe. Mailbox providers POST
 * "List-Unsubscribe=One-Click" to the List-Unsubscribe URL with no session or
 * CSRF token; the unsubscribe token in the URL is the credential. (CSRF itself is
 * covered by CsrfExemptRoutesTest, because Laravel skips it during tests.)
 */
class OneClickUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_click_post_unsubscribes_and_returns_200(): void
    {
        $recipient = $this->recipient();

        $this->flushHeaders()
            ->call('POST', $recipient->getOneClickUnsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();

        $this->assertTrue(MarketingSubscription::isSuppressed('ada@example.com'));
        $this->assertSame(RecipientStatus::Unsubscribed, $recipient->fresh()?->status);
        $this->assertSame(1, $recipient->campaign->fresh()?->unsubscribes_count);
    }

    public function test_the_one_click_url_is_the_unsubscribe_page_url(): void
    {
        $recipient = $this->recipient();

        // Clients that only open the List-Unsubscribe link (GET) get the confirmation page.
        $this->assertSame($recipient->getUnsubscribeUrl(), $recipient->getOneClickUnsubscribeUrl());
        $this->get($recipient->getOneClickUnsubscribeUrl())->assertOk()->assertSee('Confirm Unsubscribe');
    }

    public function test_a_repeated_one_click_post_is_harmless(): void
    {
        $recipient = $this->recipient();

        $this->post($recipient->getOneClickUnsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->post($recipient->getOneClickUnsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertSame(1, $recipient->campaign->fresh()?->unsubscribes_count);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->recipient();

        $this->post(route('focal.marketing.unsubscribe.process', 'not-a-real-token'), ['List-Unsubscribe' => 'One-Click'])
            ->assertNotFound();

        $this->assertFalse(MarketingSubscription::isSuppressed('ada@example.com'));
    }

    private function recipient(): CampaignRecipient
    {
        $campaign = Campaign::create([
            'name' => 'Newsletter',
            'subject' => 'News',
            'sender_name' => 'Focal',
            'sender_email' => 'news@example.com',
            'status' => CampaignStatus::Sent,
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
