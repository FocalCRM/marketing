<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Enums\SubscriptionStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\EspEvent;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EspDeliverabilityWebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailgun_bounce_webhook_automatically_suppresses_recipient(): void
    {
        $contact = Contact::create([
            'first_name' => 'Bad',
            'last_name' => 'Mailbox',
            'email' => 'nonexistent@invalid-domain.test',
            'lead_score' => 50,
        ]);

        $campaign = Campaign::create([
            'name' => 'Product Newsletter',
            'subject' => 'Newsletter',
            'sender_name' => 'Focal',
            'sender_email' => 'newsletter@focal.test',
            'status' => CampaignStatus::Sent,
        ]);

        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
            'tracking_token' => 'token_mailgun_bounce_123',
        ]);

        $payload = [
            'event-data' => [
                'event' => 'bounce',
                'recipient' => 'nonexistent@invalid-domain.test',
                'delivery-status' => [
                    'code' => '550',
                    'message' => '5.1.1 User unknown',
                ],
                'user-variables' => [
                    'focal_token' => 'token_mailgun_bounce_123',
                ],
            ],
        ];

        $response = $this->postJson('/marketing/webhooks/esp/mailgun', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'received', 'event_type' => 'bounce']);

        // Assert EspEvent logged
        $this->assertDatabaseHas('focal_marketing_esp_events', [
            'provider' => 'mailgun',
            'event_type' => 'bounce',
            'email' => 'nonexistent@invalid-domain.test',
            'recipient_id' => $recipient->id,
            'error_code' => '550',
        ]);

        // Assert subscription suppressed
        $subscription = MarketingSubscription::query()->where('email', 'nonexistent@invalid-domain.test')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Bounced, $subscription->status);

        // Assert recipient status updated
        $recipient->refresh();
        $this->assertSame(RecipientStatus::Bounced, $recipient->status);
    }

    public function test_ses_spam_complaint_webhook_unsubscribes_contact_and_deducts_lead_score(): void
    {
        $contact = Contact::create([
            'first_name' => 'Spam',
            'last_name' => 'Reporter',
            'email' => 'complainer@isp.test',
            'lead_score' => 60,
        ]);

        $campaign = Campaign::create([
            'name' => 'Promotional Blast',
            'subject' => 'Special Promo',
            'sender_name' => 'Focal',
            'sender_email' => 'promo@focal.test',
        ]);

        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
        ]);

        $payload = [
            'eventType' => 'complaint',
            'mail' => [
                'destination' => ['complainer@isp.test'],
            ],
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
            ],
        ];

        $response = $this->postJson('/marketing/webhooks/esp/ses', $payload);

        $response->assertStatus(200);

        // Assert subscription is marked unsubscribed
        $subscription = MarketingSubscription::query()->where('email', 'complainer@isp.test')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $subscription->status);

        // Assert recipient is marked unsubscribed
        $recipient->refresh();
        $this->assertSame(RecipientStatus::Unsubscribed, $recipient->status);

        // Assert lead score penalized (-50 points: 60 - 50 = 10)
        $contact->refresh();
        $this->assertSame(10, $contact->lead_score);
    }
}
