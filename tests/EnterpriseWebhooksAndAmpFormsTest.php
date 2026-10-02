<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use DoPHP\MailBuilder\Mail\TemplateMailable;
use Focal\Core\Models\Contact;
use Focal\Marketing\Models\MarketingEvent;
use Focal\Marketing\Models\MarketingEventRegistration;
use Focal\Marketing\Models\MarketingTemplate;
use Focal\Marketing\Models\NpsResponse;
use Focal\Marketing\Models\NpsSurvey;
use Focal\Marketing\Services\AbTestSignificanceCalculator;
use Focal\Marketing\Services\ContactPersonaPreviewService;
use Focal\Marketing\Services\DomainThrottler;
use Focal\Marketing\Services\MarketingWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class EnterpriseWebhooksAndAmpFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_dispatcher_generates_and_verifies_signatures(): void
    {
        $secret = 'whsec_enterprise_top_secret_test';
        $payload = json_encode(['event' => 'template.email.sent', 'data' => ['id' => 123]]);
        $timestamp = time();

        $signature = MarketingWebhookDispatcher::generateSignature($timestamp, $payload, $secret);

        expect($signature)->toContain("t={$timestamp},v1=");

        $isValid = MarketingWebhookDispatcher::verifySignature($payload, $signature, $secret);
        expect($isValid)->toBeTrue();

        // Tampered payload fails
        $isTamperedValid = MarketingWebhookDispatcher::verifySignature('{"tampered": true}', $signature, $secret);
        expect($isTamperedValid)->toBeFalse();

        // Expired timestamp fails
        $expiredSig = MarketingWebhookDispatcher::generateSignature(time() - 600, $payload, $secret);
        $isExpiredValid = MarketingWebhookDispatcher::verifySignature($payload, $expiredSig, $secret, tolerance: 300);
        expect($isExpiredValid)->toBeFalse();
    }

    public function test_transactional_send_dispatches_signed_outbound_webhook(): void
    {
        Mail::fake();
        Http::fake([
            'https://webhook.site/*' => Http::response(['status' => 'received'], 200),
        ]);

        $template = MarketingTemplate::create([
            'name' => 'Invoice Notice',
            'slug' => 'invoice-notice',
            'subject' => 'Invoice #100 Ready',
            'body_html' => '<p>Your invoice is available.</p>',
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => $template->slug]), [
            'to' => 'billing@enterprise.com',
            'webhook_url' => 'https://webhook.site/inbound-events',
            'webhook_secret' => 'whsec_12345',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://webhook.site/inbound-events'
                && $request->header('X-Focal-Event')[0] === 'template.email.sent'
                && ! empty($request->header('X-Focal-Signature')[0])
                && $request['data']['recipient'] === 'billing@enterprise.com';
        });
    }

    public function test_transactional_batch_supports_domain_throttling_plan(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'Newsletter Batch',
            'slug' => 'newsletter-batch',
            'subject' => 'Monthly Dispatch',
            'body_html' => '<p>Monthly update content</p>',
        ]);

        $recipients = [
            ['to' => 'user1@yahoo.com'],
            ['to' => 'user2@yahoo.com'],
            ['to' => 'user1@gmail.com'],
            ['to' => 'user1@custom.org'],
        ];

        $response = $this->postJson(route('focal.marketing.templates.send-batch', ['template' => $template->slug]), [
            'recipients' => $recipients,
            'throttle_domains' => true,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'template_slug',
            'dispatched_count',
            'throttle_plan' => [
                'total_recipients',
                'domain_distribution',
                'waves',
                'estimated_dispatch_duration_seconds',
            ],
        ]);

        $plan = $response->json('throttle_plan');
        expect($plan['total_recipients'])->toBe(4);
        expect($plan['domain_distribution']['yahoo.com'])->toBe(2);
        expect($plan['domain_distribution']['gmail.com'])->toBe(1);
    }

    public function test_domain_throttler_partitions_large_batches_into_waves(): void
    {
        $recipients = [];
        // Add 150 Yahoo recipients (limit 60/min -> should span 3 waves)
        for ($i = 0; $i < 150; $i++) {
            $recipients[] = ['to' => "recipient{$i}@yahoo.com"];
        }

        $plan = DomainThrottler::calculateThrottledBatches($recipients);

        expect($plan['total_recipients'])->toBe(150);
        expect($plan['waves'])->toHaveCount(3);
        expect($plan['waves'][0]['count'])->toBe(60);
        expect($plan['waves'][1]['count'])->toBe(60);
        expect($plan['waves'][2]['count'])->toBe(30);
        expect($plan['waves'][1]['offset_seconds'])->toBe(60);
        expect($plan['waves'][2]['offset_seconds'])->toBe(120);
        expect($plan['estimated_dispatch_duration_seconds'])->toBe(120);
    }

    public function test_amp_feedback_endpoint_records_rating_with_cors(): void
    {
        $survey = NpsSurvey::create([
            'name' => 'Product CSAT',
            'is_active' => true,
        ]);

        $nps = NpsResponse::create([
            'survey_id' => $survey->id,
            'score' => 0,
            'category' => 'passive',
            'token' => 'nps_token_amp_123',
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://mail.google.com',
            'AMP-Email-Sender' => 'surveys@focal.test',
        ])->postJson(route('focal.marketing.amp.feedback'), [
            'token' => 'nps_token_amp_123',
            'score' => 9,
            'feedback' => 'Loved the interactive email experience!',
        ]);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://mail.google.com')
            ->assertHeader('AMP-Email-Allow-Sender', 'surveys@focal.test')
            ->assertHeader('Access-Control-Expose-Headers', 'AMP-Email-Allow-Sender')
            ->assertJson([
                'status' => 'success',
                'score' => 9,
            ]);

        $nps->refresh();
        expect($nps->score)->toBe(9);
        expect($nps->feedback)->toBe('Loved the interactive email experience!');
        expect($nps->responded_at)->not->toBeNull();
    }

    public function test_amp_rsvp_endpoint_registers_attendee(): void
    {
        $event = MarketingEvent::create([
            'title' => 'Product Keynote 2026',
            'slug' => 'keynote-2026',
            'starts_at' => now()->addDays(7),
            'status' => 'published',
        ]);

        $contact = Contact::create(['first_name' => 'Jordan', 'email' => 'keynote_fan@example.com']);

        $response = $this->withHeaders(['Origin' => 'https://mail.google.com'])->postJson(route('focal.marketing.amp.rsvp'), [
            'event_slug' => 'keynote-2026',
            'token' => $event->rsvpTokenFor($contact),
            'status' => 'attending',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'event' => 'keynote-2026',
                'rsvp_status' => 'attending',
            ]);

        $registration = MarketingEventRegistration::where('event_id', $event->id)
            ->where('contact_id', $contact->id)
            ->first();

        expect($registration)->not->toBeNull();
        expect($registration->status)->toBe('attending');
    }

    public function test_ab_test_significance_calculator_detects_winning_variant(): void
    {
        // Variant A: 1,000 sent, 40 clicks (4.0%)
        // Variant B: 1,000 sent, 80 clicks (8.0%) -> 100% relative uplift, highly significant
        $result = AbTestSignificanceCalculator::calculate(
            sampleA: 1000,
            conversionsA: 40,
            sampleB: 1000,
            conversionsB: 80,
            confidenceThreshold: 0.95
        );

        expect($result['is_significant'])->toBeTrue();
        expect($result['winning_variant'])->toBe('B');
        expect($result['confidence_percent'])->toBeGreaterThanOrEqual(99.0);
        expect($result['relative_uplift_percent'])->toBe(100.0);
        expect($result['recommendation'])->toContain('Variant B is winning');

        // Test small sample / inconclusive
        $inconclusive = AbTestSignificanceCalculator::calculate(
            sampleA: 20,
            conversionsA: 1,
            sampleB: 20,
            conversionsB: 2
        );

        expect($inconclusive['is_significant'])->toBeFalse();
        expect($inconclusive['winning_variant'])->toBeNull();
        expect($inconclusive['recommendation'])->toContain('Sample size is too small');
    }

    public function test_contact_persona_preview_service_evaluates_slots_and_tokens(): void
    {
        $slots = [
            [
                'type' => 'header',
                'data' => ['brand_name' => 'Focal HQ'],
            ],
            [
                'type' => 'body_text',
                'data' => ['content' => 'Hello {{ contact.first_name }}! Welcome to {{ company.name }}.'],
            ],
            [
                'type' => 'button',
                'data' => ['button_text' => 'VIP Lounge Access', 'button_url' => 'https://vip.example.com'],
                'visibility' => [
                    'field' => 'contact.lifecycle_stage',
                    'operator' => 'equals',
                    'value' => 'customer',
                ],
            ],
        ];

        // Preview as VIP Customer persona
        $preview = ContactPersonaPreviewService::preview($slots, 'vip_customer');

        expect($preview['persona_label'])->toContain('Sophia Laurent');
        expect($preview['compiled_html'])
            ->toContain('Hello Sophia!')
            ->toContain('Welcome to Acme Global.')
            ->toContain('VIP Lounge Access');
        expect($preview['visible_slots_count'])->toBe(3);
        expect($preview['hidden_slots_count'])->toBe(0);

        // Preview as Lead / Trial user (button should be hidden)
        $previewLead = ContactPersonaPreviewService::preview($slots, 'trial_user');
        expect($previewLead['compiled_html'])
            ->toContain('Hello Marcus!')
            ->not->toContain('VIP Lounge Access');
        expect($previewLead['visible_slots_count'])->toBe(2);
        expect($previewLead['hidden_slots_count'])->toBe(1);
    }

    public function test_transactional_send_supports_attachments(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'Invoice Receipt',
            'slug' => 'invoice-receipt',
            'subject' => 'Your Attached Invoice',
            'body_html' => '<p>Please find attached your invoice.</p>',
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => $template->slug]), [
            'to' => 'accountant@client.com',
            'attachments' => [
                [
                    'name' => 'invoice_001.pdf',
                    'data' => base64_encode('%PDF-1.4 test document'),
                    'is_base64' => true,
                    'mime' => 'application/pdf',
                ],
            ],
        ]);

        $response->assertOk();

        Mail::assertQueued(TemplateMailable::class, function ($mailable) {
            $attachments = $mailable->attachments();
            expect($attachments)->toHaveCount(1);

            return $mailable->hasTo('accountant@client.com');
        });
    }
}
