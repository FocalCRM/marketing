<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\EspEvent;

class MailgunWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNING_KEY = 'mailgun-signing-key';

    private const URL = '/marketing/webhooks/esp/mailgun';

    protected function setUp(): void
    {
        parent::setUp();

        config(['odden-marketing.esp.mailgun.signing_key' => self::SIGNING_KEY]);
        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function signed(array $eventData, ?int $timestamp = null, ?string $token = null, ?string $key = null): array
    {
        $timestamp ??= time();
        $token ??= bin2hex(random_bytes(25));

        return [
            'signature' => [
                'timestamp' => (string) $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp.$token, $key ?? self::SIGNING_KEY),
            ],
            'event-data' => $eventData,
        ];
    }

    public function test_a_permanent_failure_suppresses_the_address_as_a_hard_bounce(): void
    {
        $this->postJson(self::URL, $this->signed([
            'event' => 'failed',
            'severity' => 'permanent',
            'recipient' => 'Gone@Example.com',
            'delivery-status' => ['code' => 550, 'message' => '5.1.1 User unknown'],
        ]))->assertOk()->assertJson(['status' => 'received', 'event_type' => 'hard_bounce']);

        $this->assertDatabaseHas('odden_marketing_esp_events', ['provider' => 'mailgun', 'email' => 'gone@example.com', 'event_type' => 'hard_bounce']);
        $this->assertTrue(EmailSuppression::isSuppressed('gone@example.com'));
        $this->assertDatabaseHas('odden_marketing_suppressions', ['email' => 'gone@example.com', 'reason' => 'hard_bounce', 'source' => 'esp_webhook:mailgun']);
    }

    public function test_a_temporary_failure_is_recorded_but_suppresses_nobody(): void
    {
        $this->postJson(self::URL, $this->signed([
            'event' => 'failed',
            'severity' => 'temporary',
            'recipient' => 'full@example.com',
            'delivery-status' => ['code' => 452, 'message' => 'Mailbox full'],
        ]))->assertOk()->assertJson(['event_type' => 'soft_bounce']);

        $this->assertSame(1, EspEvent::query()->where('event_type', 'soft_bounce')->count());
        $this->assertFalse(EmailSuppression::isSuppressed('full@example.com'));
    }

    public function test_a_complaint_suppresses_the_address_as_a_spam_complaint(): void
    {
        $this->postJson(self::URL, $this->signed([
            'event' => 'complained',
            'recipient' => 'unhappy@example.com',
        ]))->assertOk()->assertJson(['event_type' => 'complaint']);

        $this->assertDatabaseHas('odden_marketing_suppressions', ['email' => 'unhappy@example.com', 'reason' => 'spam_complaint']);
    }

    public function test_a_delivered_event_is_recorded_without_suppressing(): void
    {
        $this->postJson(self::URL, $this->signed(['event' => 'delivered', 'recipient' => 'fine@example.com']))
            ->assertOk()->assertJson(['event_type' => 'delivered']);

        $this->assertFalse(EmailSuppression::isSuppressed('fine@example.com'));
    }

    public function test_a_wrong_signature_is_rejected_and_nothing_is_recorded(): void
    {
        $this->postJson(self::URL, $this->signed(['event' => 'complained', 'recipient' => 'victim@example.com'], key: 'some-other-key'))
            ->assertUnauthorized();

        $this->assertSame(0, EspEvent::query()->count());
        $this->assertFalse(EmailSuppression::isSuppressed('victim@example.com'));
    }

    public function test_the_api_token_does_not_replace_the_signature_once_a_signing_key_is_set(): void
    {
        // TestCase sends a valid X-Odden-Token on every request; with a signing key set it is not enough.
        $this->postJson(self::URL, ['event-data' => ['event' => 'complained', 'recipient' => 'victim@example.com']])
            ->assertUnauthorized();

        $this->assertFalse(EmailSuppression::isSuppressed('victim@example.com'));
    }

    public function test_a_stale_signature_is_rejected(): void
    {
        $this->postJson(self::URL, $this->signed(['event' => 'complained', 'recipient' => 'old@example.com'], timestamp: time() - 3600))
            ->assertUnauthorized();

        $this->assertFalse(EmailSuppression::isSuppressed('old@example.com'));
    }

    public function test_a_signature_cannot_be_replayed(): void
    {
        $payload = $this->signed(['event' => 'delivered', 'recipient' => 'once@example.com']);

        $this->postJson(self::URL, $payload)->assertOk();
        $this->postJson(self::URL, $payload)->assertUnauthorized();

        $this->assertSame(1, EspEvent::query()->where('email', 'once@example.com')->count());
    }

    public function test_a_malformed_signature_block_is_rejected(): void
    {
        $this->postJson(self::URL, ['signature' => 'nope', 'event-data' => ['event' => 'complained', 'recipient' => 'x@example.com']])
            ->assertUnauthorized();

        $this->postJson(self::URL, ['signature' => ['timestamp' => time(), 'token' => '', 'signature' => 'abc'], 'event-data' => ['event' => 'complained', 'recipient' => 'x@example.com']])
            ->assertUnauthorized();
    }

    public function test_without_a_signing_key_mailgun_webhooks_still_use_the_api_token(): void
    {
        config(['odden-marketing.esp.mailgun.signing_key' => null]);

        $this->postJson(self::URL, ['event-data' => ['event' => 'delivered', 'recipient' => 'token@example.com']])->assertOk();

        $this->withHeader('X-Odden-Token', 'wrong')
            ->postJson(self::URL, ['event-data' => ['event' => 'delivered', 'recipient' => 'token@example.com']])
            ->assertUnauthorized();
    }

    public function test_other_providers_are_unaffected_by_the_mailgun_signing_key(): void
    {
        $this->postJson('/marketing/webhooks/esp/sendgrid', ['email' => 'sg@example.com', 'event' => 'delivered'])->assertOk();

        $this->withHeader('X-Odden-Token', 'wrong')
            ->postJson('/marketing/webhooks/esp/sendgrid', ['email' => 'sg@example.com', 'event' => 'delivered'])
            ->assertUnauthorized();
    }
}
