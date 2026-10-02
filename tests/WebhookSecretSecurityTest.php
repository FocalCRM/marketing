<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Services\MarketingWebhookDispatcher;

/**
 * Outbound webhooks are never signed with a guessable default secret.
 */
class WebhookSecretSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_config_keys_default_to_null(): void
    {
        $this->assertNull(config('odden-marketing.webhooks.outbound_url'));
        $this->assertNull(config('odden-marketing.webhooks.secret'));
    }

    public function test_webhook_is_not_sent_without_a_secret(): void
    {
        Http::fake();
        Log::spy();

        $sent = MarketingWebhookDispatcher::dispatch('template.email.sent', ['id' => 1], 'https://hooks.example.test/in');

        $this->assertFalse($sent);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_transactional_send_skips_the_webhook_without_a_secret(): void
    {
        Http::fake();
        Mail::fake();
        MarketingTemplate::create(['name' => 'Receipt', 'slug' => 'receipt', 'subject' => 'Receipt', 'body_html' => '<p>Hi</p>']);

        $this->postJson(route('odden.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'webhook_url' => 'https://hooks.example.test/in',
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_webhook_is_signed_with_the_configured_secret(): void
    {
        Http::fake();
        config([
            'odden-marketing.webhooks.outbound_url' => 'https://hooks.example.test/in',
            'odden-marketing.webhooks.secret' => 'whsec_configured',
        ]);

        $this->assertTrue(MarketingWebhookDispatcher::dispatch('template.email.sent', ['id' => 1]));

        Http::assertSent(fn (Request $request): bool => MarketingWebhookDispatcher::verifySignature(
            $request->body(),
            $request->header('X-Odden-Signature')[0],
            'whsec_configured',
        ));
    }

    public function test_signature_verifies_against_the_raw_body_that_was_sent(): void
    {
        Http::fake();

        // Slashes are where an encoding mismatch between the signed and sent JSON shows up.
        $this->assertTrue(MarketingWebhookDispatcher::dispatch(
            'template.email.sent',
            ['url' => 'https://example.com/a/b', 'note' => 'café & "quotes"'],
            'https://hooks.example.test/in',
            'whsec_raw_body',
        ));

        Http::assertSent(fn (Request $request): bool => $request['data']['url'] === 'https://example.com/a/b'
            && $request->header('Content-Type')[0] === 'application/json'
            && MarketingWebhookDispatcher::verifySignature(
                $request->body(),
                $request->header('X-Odden-Signature')[0],
                'whsec_raw_body',
            ));
    }
}
