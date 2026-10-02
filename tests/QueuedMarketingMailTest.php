<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use DoPHP\MailBuilder\Mail\TemplateMailable;
use Focal\Marketing\Actions\SendCampaignProofAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Mail\CampaignProofMailable;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * #17: proofs and the transactional API queue their mail on the configured
 * marketing queue instead of sending during the request.
 */
class QueuedMarketingMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['focal-marketing.mail.queue' => 'marketing-mail', 'focal-marketing.mail.mailer' => 'array']);
    }

    public function test_campaign_proofs_are_queued(): void
    {
        $campaign = Campaign::create([
            'name' => 'Launch',
            'subject' => 'Launch',
            'sender_name' => 'Focal',
            'sender_email' => 'news@example.com',
            'status' => CampaignStatus::Draft,
        ]);

        $result = app(SendCampaignProofAction::class)->execute($campaign, 'reviewer@example.com');

        $this->assertTrue($result['success']);
        Mail::assertNothingSent();
        Mail::assertQueued(CampaignProofMailable::class, fn (CampaignProofMailable $mail): bool => $mail instanceof ShouldQueue
            && $mail->queue === 'marketing-mail'
            && $mail->mailer === 'array'
            && $mail->hasTo('reviewer@example.com'));
    }

    public function test_the_transactional_api_queues_and_returns(): void
    {
        MarketingTemplate::create([
            'name' => 'Receipt',
            'slug' => 'receipt',
            'subject' => 'Receipt {{order_id}}',
            'body_html' => '<p>Order {{order_id}}</p>',
        ]);

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'customer@example.com',
            'data' => ['order_id' => 'A-1'],
        ])->assertOk()->assertJson(['success' => true, 'queued' => true]);

        Mail::assertNothingSent();
        Mail::assertQueued(TemplateMailable::class, fn (TemplateMailable $mail): bool => $mail instanceof ShouldQueue
            && $mail->queue === 'marketing-mail'
            && $mail->mailer === 'array'
            && $mail->hasTo('customer@example.com')
            && $mail->subjectLine === 'Receipt A-1');
    }

    public function test_the_transactional_batch_api_queues_each_message(): void
    {
        MarketingTemplate::create([
            'name' => 'Notice',
            'slug' => 'notice',
            'subject' => 'Notice',
            'body_html' => '<p>Notice</p>',
        ]);

        $this->postJson(route('focal.marketing.templates.send-batch', ['template' => 'notice']), [
            'recipients' => [['to' => 'one@example.com'], ['to' => 'two@example.com']],
        ])->assertOk()->assertJson(['success' => true, 'queued' => true, 'dispatched_count' => 2]);

        Mail::assertNothingSent();
        Mail::assertQueued(TemplateMailable::class, fn (TemplateMailable $mail): bool => $mail instanceof ShouldQueue && $mail->queue === 'marketing-mail', 2);
    }
}
