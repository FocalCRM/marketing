<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Enums\SubscriptionStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DeliverabilityAndEspWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_sendgrid_bounce_webhook_suppresses_email_and_increments_campaign_bounces(): void
    {
        $contact = Contact::create([
            'first_name' => 'Bounced',
            'last_name' => 'User',
            'email' => 'bad-email@example.com',
        ]);

        $campaign = Campaign::create([
            'name' => 'Fall Product Launch',
            'subject' => 'New Release Announcement',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'status' => CampaignStatus::Sending,
            'bounces_count' => 0,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'sg_tok_123',
            'unsubscribe_token' => 'sg_unsub_123',
            'status' => RecipientStatus::Sent,
        ]);

        $response = $this->postJson('/marketing/webhooks/esp/sendgrid', [
            'email' => 'bad-email@example.com',
            'event' => 'bounce',
            'status' => '5.1.1',
            'reason' => '550 5.1.1 Mailbox does not exist',
            'focal_token' => 'sg_tok_123',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'event_type' => 'bounce',
        ]);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Bounced, $recipient->status);

        $campaign->refresh();
        $this->assertSame(1, $campaign->bounces_count);

        $subscription = MarketingSubscription::where('email', 'bad-email@example.com')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Bounced, $subscription->status);
        $this->assertTrue(MarketingSubscription::isSuppressed('bad-email@example.com'));
    }

    public function test_resend_spam_complaint_webhook_suppresses_email_and_marks_unsubscribed(): void
    {
        $contact = Contact::create([
            'first_name' => 'Complaining',
            'last_name' => 'Customer',
            'email' => 'unhappy@example.com',
        ]);

        $campaign = Campaign::create([
            'name' => 'Newsletter Week 42',
            'subject' => 'Weekly Digest',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'unsubscribes_count' => 0,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'resend_tok_456',
            'unsubscribe_token' => 'resend_unsub_456',
            'status' => RecipientStatus::Sent,
        ]);

        $response = $this->postJson('/marketing/webhooks/esp/resend', [
            'type' => 'email.complained',
            'data' => [
                'to' => ['unhappy@example.com'],
                'tags' => [
                    'focal_token' => 'resend_tok_456',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'event_type' => 'complaint',
        ]);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Unsubscribed, $recipient->status);

        $campaign->refresh();
        $this->assertSame(1, $campaign->unsubscribes_count);

        $subscription = MarketingSubscription::where('email', 'unhappy@example.com')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $subscription->status);
        $this->assertTrue(MarketingSubscription::isSuppressed('unhappy@example.com'));
    }

    public function test_unified_deliverability_endpoint_processes_batch_events(): void
    {
        $response = $this->postJson('/api/marketing/webhooks/deliverability', [
            [
                'email' => 'batch1@example.com',
                'event_type' => 'bounce',
                'reason' => 'Host unknown',
            ],
            [
                'email' => 'batch2@example.com',
                'event_type' => 'complaint',
                'reason' => 'Reported spam',
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'count' => 2,
        ]);

        $this->assertTrue(MarketingSubscription::isSuppressed('batch1@example.com'));
        $this->assertTrue(MarketingSubscription::isSuppressed('batch2@example.com'));
    }
}
