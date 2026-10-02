<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Marketing\Actions\CompileCampaignMessageAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The click redirect must only send people to destinations the app itself signed.
 */
class ClickTrackingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private CampaignRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $campaign = Campaign::create([
            'name' => 'Launch',
            'subject' => 'Launch',
            'sender_name' => 'Focal',
            'sender_email' => 'focal@test.com',
            'status' => CampaignStatus::Sent,
        ]);

        $this->recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'test@example.com',
            'status' => RecipientStatus::Sent,
        ]);
    }

    public function test_signed_click_link_redirects_and_records_the_click(): void
    {
        $url = 'https://focal.test/pricing?plan=pro&utm_source=email';

        $this->get($this->recipient->getClickRedirectUrl($url))->assertRedirect($url);

        $this->assertNotNull($this->recipient->fresh()->clicked_at);
    }

    public function test_unsigned_or_tampered_click_links_do_not_redirect(): void
    {
        $base = '/marketing/track/click/'.$this->recipient->tracking_token;
        $evil = 'https://evil.test/phish';

        $this->get($base.'?url='.urlencode($evil))->assertNotFound();
        $this->get('/marketing/track/click/unknown-token?url='.urlencode($evil))->assertNotFound();

        $signed = $this->recipient->getClickRedirectUrl('https://focal.test/pricing');
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        $this->get($base.'?url='.urlencode($evil).'&sig='.$query['sig'])->assertNotFound();
        $this->get('/marketing/track/click/other-token?url='.urlencode('https://focal.test/pricing').'&sig='.$query['sig'])->assertNotFound();
        $this->get($base.'?url='.urlencode('https://focal.test/pricing').'&sig='.str_repeat('0', 64))->assertNotFound();

        $this->assertNull($this->recipient->fresh()->clicked_at);
    }

    public function test_signed_links_to_non_http_urls_do_not_redirect(): void
    {
        $this->get($this->recipient->getClickRedirectUrl('javascript:alert(1)'))->assertNotFound();
        $this->get($this->recipient->getClickRedirectUrl('ftp://focal.test/file'))->assertNotFound();

        $this->assertNull($this->recipient->fresh()->clicked_at);
    }

    public function test_compiled_campaign_links_are_signed_and_followable(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Newsletter',
            'subject' => 'News',
            'body_html' => '<html><body><a href="https://focal.test/pricing">Pricing</a></body></html>',
        ]);
        $this->recipient->campaign->update(['template_id' => $template->id]);

        $html = (new CompileCampaignMessageAction)->execute($this->recipient->campaign->fresh(), $this->recipient);

        $this->assertSame(1, preg_match('/href="([^"]*track\/click[^"]*)"/', $html, $matches));
        $this->assertStringContainsString('sig=', $matches[1]);

        $this->get(html_entity_decode($matches[1]))->assertRedirect();
        $this->assertNotNull($this->recipient->fresh()->clicked_at);
    }

    public function test_links_signed_with_a_previous_app_key_still_redirect(): void
    {
        $url = 'https://focal.test/pricing';
        $signedWithOldKey = $this->recipient->getClickRedirectUrl($url);

        config(['app.previous_keys' => [config('app.key')], 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->get($signedWithOldKey)->assertRedirect($url);

        config(['app.previous_keys' => []]);
        $this->get($signedWithOldKey)->assertNotFound();
    }
}
