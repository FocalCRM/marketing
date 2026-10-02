<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactional_send_api_rejects_requests_without_the_token_and_sends_nothing(): void
    {
        Mail::fake();
        $template = MarketingTemplate::create([
            'name' => 'Receipt',
            'slug' => 'receipt',
            'subject' => 'Your receipt',
            'body_html' => '<p>Thanks!</p>',
        ]);

        $this->flushHeaders()
            ->postJson(route('focal.marketing.templates.send', ['template' => $template->slug]), ['to' => 'victim@example.com'])
            ->assertUnauthorized();

        $this->withToken('wrong-token')
            ->postJson(route('focal.marketing.templates.send-batch', ['template' => $template->slug]), ['recipients' => [['email' => 'victim@example.com']]])
            ->assertUnauthorized();

        Mail::assertNothingOutgoing();
    }

    public function test_webhooks_reject_requests_without_the_token(): void
    {
        $this->flushHeaders();

        $this->postJson('/api/marketing/leads/webhook/zapier', ['email' => 'spam@example.com'])->assertUnauthorized();
        $this->postJson('/api/marketing/webhooks/deliverability', [])->assertUnauthorized();
        $this->postJson('/marketing/webhooks/esp/sendgrid', [])->assertUnauthorized();
        $this->postJson('/api/marketing/events/track', ['email' => 'spam@example.com', 'event' => 'x'])->assertUnauthorized();

        $this->assertFalse(Contact::query()->where('email', 'spam@example.com')->exists());
    }

    public function test_server_endpoints_are_disabled_until_a_token_is_configured(): void
    {
        config(['focal-marketing.api.token' => null]);

        $this->postJson('/api/marketing/leads/webhook/zapier', ['email' => 'lead@example.com'])->assertForbidden();
    }

    public function test_lead_webhook_accepts_the_token_as_a_query_parameter_for_url_only_providers(): void
    {
        $this->flushHeaders()
            ->postJson('/api/marketing/leads/webhook/zapier?token='.self::API_TOKEN, ['email' => 'new-lead@example.com'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(Contact::query()->where('email', 'new-lead@example.com')->exists());
    }

    public function test_public_browser_endpoints_are_rate_limited(): void
    {
        config(['focal-core.rate_limits.public' => 2]);
        $this->flushHeaders();

        $first = $this->postJson(route('focal.marketing.track.pageview'), ['url' => 'https://example.com', 'path' => '/']);
        $second = $this->postJson(route('focal.marketing.track.pageview'), ['url' => 'https://example.com', 'path' => '/']);

        $this->assertNotSame(429, $first->status());
        $this->assertNotSame(429, $second->status());
        $this->postJson(route('focal.marketing.track.pageview'), ['url' => 'https://example.com', 'path' => '/'])->assertTooManyRequests();
    }
}
