<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use DoPHP\MailBuilder\Mail\TemplateMailable;
use Focal\Marketing\Actions\EvaluateTemplateAbTestsAction;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSavedBlock;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class TransactionalTemplateApiAndRevisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_send_transactional_email_via_slug_endpoint(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'Order Confirmation Receipt',
            'slug' => 'order-receipt-notice',
            'subject' => 'Your Order #{{order_id}} is Confirmed',
            'body_html' => '<p>Hi {{contact.first_name}}, thanks for your purchase of {{order_id}}!</p>',
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => 'order-receipt-notice']), [
            'to' => 'customer@example.com',
            'name' => 'Alex Customer',
            'data' => [
                'order_id' => 'ORD-99882',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'template_slug' => 'order-receipt-notice',
                'recipient' => 'customer@example.com',
            ]);

        Mail::assertQueued(TemplateMailable::class, function (TemplateMailable $mail): bool {
            return $mail->hasTo('customer@example.com')
                && $mail->subjectLine === 'Your Order #ORD-99882 is Confirmed'
                && str_contains($mail->compiledHtml, 'Hi Alex Customer')
                && str_contains($mail->compiledHtml, 'ORD-99882');
        });
    }

    public function test_can_send_transactional_email_via_id_endpoint_with_slots(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'Visual Welcome',
            'subject' => 'Welcome to {{company_name}}',
            'slots' => [
                [
                    'type' => 'header',
                    'data' => ['brand_name' => 'Focal Cloud'],
                ],
                [
                    'type' => 'body_text',
                    'data' => ['content' => '<p>Hello {{contact.first_name}}!</p>'],
                ],
            ],
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => $template->id]), [
            'to' => 'developer@example.com',
            'data' => [
                'contact.first_name' => 'Jordan',
                'company_name' => 'Acme Inc',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'template_id' => $template->id,
            ]);

        Mail::assertQueued(TemplateMailable::class, function (TemplateMailable $mail): bool {
            return $mail->hasTo('developer@example.com')
                && $mail->subjectLine === 'Welcome to Acme Inc'
                && str_contains($mail->compiledHtml, 'Focal Cloud')
                && str_contains($mail->compiledHtml, 'Hello Jordan!');
        });
    }

    public function test_can_send_batch_transactional_emails_via_api(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'License Notice',
            'slug' => 'license-alert',
            'subject' => 'Renewal Notice for {{org_name}}',
            'body_html' => '<p>Hello {{contact.first_name}}, license expiring!</p>',
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send-batch', ['template' => 'license-alert']), [
            'recipients' => [
                [
                    'to' => 'user1@acme.com',
                    'name' => 'Alice',
                    'data' => ['org_name' => 'Acme Corp'],
                ],
                [
                    'to' => 'user2@starlight.com',
                    'name' => 'Bob',
                    'data' => ['org_name' => 'Starlight AI'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'dispatched_count' => 2,
                'template_slug' => 'license-alert',
            ]);

        Mail::assertQueued(TemplateMailable::class, 2);
    }

    public function test_send_fails_with_404_if_template_not_found(): void
    {
        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => 'non-existent-template']), [
            'to' => 'dev@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_send_validates_required_email(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Receipt',
            'subject' => 'Receipt',
            'body_html' => '<p>Test</p>',
        ]);

        $response = $this->postJson(route('focal.marketing.templates.send', ['template' => $template->id]), [
            'to' => 'not-an-email',
        ]);

        $response->assertUnprocessable();
    }

    public function test_template_automatically_creates_revisions_and_can_be_restored(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Original Template',
            'subject' => 'Original Subject',
            'preview_text' => 'Original preview snippet',
            'slots' => [
                ['type' => 'header', 'data' => ['brand_name' => 'V1 Brand']],
            ],
        ]);

        $this->assertCount(1, $template->revisions);
        $v1Revision = $template->revisions->first();
        $this->assertNotNull($v1Revision);
        $this->assertSame('Original Subject', $v1Revision->subject);

        // Update template to V2
        $template->update([
            'subject' => 'Updated V2 Subject',
            'slots' => [
                ['type' => 'header', 'data' => ['brand_name' => 'V2 Brand Updated']],
            ],
        ]);

        $template->refresh();
        $this->assertCount(2, $template->revisions);
        $this->assertSame('Updated V2 Subject', $template->subject);

        // Restore V1
        $template->restoreRevision($v1Revision);
        $template->refresh();

        $this->assertSame('Original Subject', $template->subject);
        $this->assertSame('V1 Brand', $template->slots[0]['data']['brand_name']);
        // A rollback revision is also recorded
        $this->assertCount(3, $template->revisions);
    }

    public function test_evaluate_template_ab_test_action_picks_winner(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Trial Welcome',
            'subject' => 'Variant A Subject',
            'subject_variant_b' => 'Variant B Subject',
            'body_html' => '<p>Test</p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Cohort 2026',
            'subject' => 'Trial Welcome',
            'template_id' => $template->id,
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'status' => 'sent',
        ]);

        // Variant A: 10 recipients, 2 clicks (20%)
        for ($i = 0; $i < 10; $i++) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'email' => "user_a_{$i}@test.com",
                'status' => 'sent',
                'variant' => 'A',
                'opened_at' => now(),
                'clicked_at' => $i < 2 ? now() : null,
            ]);
        }

        // Variant B: 10 recipients, 6 clicks (60%)
        for ($i = 0; $i < 10; $i++) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'email' => "user_b_{$i}@test.com",
                'status' => 'sent',
                'variant' => 'B',
                'opened_at' => now(),
                'clicked_at' => $i < 6 ? now() : null,
            ]);
        }

        $action = new EvaluateTemplateAbTestsAction;
        $result = $action->execute($template, 'click_rate');

        $this->assertSame('B', $result['winner']);
        $this->assertSame(20.0, $result['variant_a']['click_rate']);
        $this->assertSame(60.0, $result['variant_b']['click_rate']);
        $this->assertSame(20, $result['sample_size']);

        $template->refresh();
        $this->assertSame('B', $template->ab_winner_variant);
        $this->assertNotNull($template->ab_completed_at);
    }

    public function test_can_create_and_reuse_marketing_saved_block(): void
    {
        $block = MarketingSavedBlock::create([
            'name' => 'Corporate Legal Footer',
            'category' => 'footers',
            'slot_type' => 'footer',
            'slot_data' => [
                'company_name' => 'Focal Global HQ',
                'address' => '100 Montgomery St, Suite 400, San Francisco, CA',
                'unsubscribe_url' => '{{unsubscribe_url}}',
            ],
        ]);

        $this->assertDatabaseHas('focal_marketing_saved_blocks', [
            'name' => 'Corporate Legal Footer',
            'slot_type' => 'footer',
        ]);

        $this->assertSame('Focal Global HQ', $block->slot_data['company_name']);
    }

    public function test_countdown_timer_svg_endpoint_returns_realtime_image(): void
    {
        $response = $this->get(route('focal.marketing.images.countdown-timer', [
            'until' => now()->addDays(2)->toIso8601String(),
            'label' => 'SUMMIT DEAL ENDS IN',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('SUMMIT DEAL ENDS IN', (string) $response->getContent());
        $this->assertStringContainsString('DAYS', (string) $response->getContent());
        $this->assertStringContainsString('HOURS', (string) $response->getContent());
    }

    public function test_personalized_badge_svg_endpoint_returns_svg(): void
    {
        $response = $this->get(route('focal.marketing.images.badge', [
            'name' => 'Dr. Elena Rostova',
            'company' => 'Quantum Nexus',
            'role' => 'Keynote Speaker',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('Dr. Elena Rostova', (string) $response->getContent());
        $this->assertStringContainsString('Quantum Nexus', (string) $response->getContent());
        $this->assertStringContainsString('Keynote Speaker', (string) $response->getContent());
    }

    public function test_template_can_store_and_retrieve_localized_translations(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Global Product Launch',
            'subject' => 'Welcome to Focal (English)',
            'subject_variant_b' => 'Discover Focal (English B)',
            'body_html' => '<p>Welcome</p>',
        ]);

        $translation = $template->translations()->create([
            'locale' => 'es',
            'subject' => 'Bienvenido a Focal',
            'subject_variant_b' => 'Descubre Focal',
            'preview_text' => 'Texto preliminar',
        ]);

        $this->assertSame('Bienvenido a Focal', $template->getLocalizedSubject('es', 'A'));
        $this->assertSame('Descubre Focal', $template->getLocalizedSubject('es', 'B'));
        // Fallback to default when locale does not exist
        $this->assertSame('Welcome to Focal (English)', $template->getLocalizedSubject('de', 'A'));
    }
}
