<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Core\Models\CrmList;
use Focal\Marketing\Actions\DispatchCampaignAction;
use Focal\Marketing\Actions\ProcessEspWebhookAction;
use Focal\Marketing\Actions\SendCampaignProofAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\EmailSuppression;
use Focal\Marketing\Models\MarketingSubscription;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSuppressionAndProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_suppression_crud_and_checks(): void
    {
        $this->assertFalse(EmailSuppression::isSuppressed('blocked@example.com'));

        EmailSuppression::suppress(
            email: 'blocked@example.com',
            reason: 'spam_complaint',
            source: 'manual',
            metadata: ['notes' => 'User requested manual blocklist']
        );

        $this->assertTrue(EmailSuppression::isSuppressed('blocked@example.com'));
        $this->assertTrue(EmailSuppression::isSuppressed('BLOCKED@EXAMPLE.COM'));

        // Check MarketingSubscription::isSuppressed reflects this
        $this->assertTrue(MarketingSubscription::isSuppressed('blocked@example.com'));

        // Remove suppression
        $removed = EmailSuppression::remove('blocked@example.com');
        $this->assertTrue($removed);
        $this->assertFalse(EmailSuppression::isSuppressed('blocked@example.com'));
    }

    public function test_process_esp_webhook_records_email_suppression_on_hard_bounce_and_complaint(): void
    {
        $action = new ProcessEspWebhookAction;

        $action->execute('sendgrid', [
            'event' => 'bounce',
            'type' => 'bounce',
            'email' => 'bad-mailbox@domain.com',
            'reason' => '550 5.1.1 User unknown',
        ]);

        $this->assertTrue(EmailSuppression::isSuppressed('bad-mailbox@domain.com'));
        $suppression = EmailSuppression::query()->where('email', 'bad-mailbox@domain.com')->first();
        $this->assertNotNull($suppression);
        $this->assertSame('hard_bounce', $suppression->reason);
        $this->assertSame('esp_webhook:sendgrid', $suppression->source);
    }

    public function test_dispatch_campaign_suppresses_emails_on_suppression_list(): void
    {
        EmailSuppression::suppress('suppressed-user@domain.com', 'hard_bounce');

        $activeContact = Contact::factory()->create(['email' => 'active-user@domain.com']);
        $suppressedContact = Contact::factory()->create(['email' => 'suppressed-user@domain.com']);

        $list = CrmList::create(['name' => 'Target Audience', 'type' => 'static']);
        $list->addMember($activeContact);
        $list->addMember($suppressedContact);

        $template = MarketingTemplate::create([
            'name' => 'Newsletter',
            'subject' => 'Monthly Insights',
            'body_html' => '<p>Hello {{contact.first_name}}</p>',
            'category' => 'newsletter',
        ]);

        $campaign = Campaign::create([
            'name' => 'Q3 Update',
            'subject' => 'Updates',
            'sender_name' => 'Marketing Team',
            'sender_email' => 'news@focal.test',
            'template_id' => $template->id,
            'list_id' => $list->id,
            'status' => CampaignStatus::Draft,
        ]);

        $result = (new DispatchCampaignAction)->execute($campaign);

        $this->assertSame(2, $result['total_recipients']);
        $this->assertSame(1, $result['delivered_count']);
        $this->assertSame(1, $result['suppressed_count']);
    }

    public function test_send_campaign_proof_action_dispatches_test_email_with_merge_tags(): void
    {
        Mail::fake();

        $company = Company::factory()->create(['name' => 'Acme Labs']);
        $contact = Contact::factory()->create([
            'first_name' => 'Devon',
            'last_name' => 'Vance',
            'email' => 'devon@acmelabs.com',
        ]);
        $contact->companies()->attach($company->id, [
            'parent_type' => $contact->getMorphClass(),
            'child_type' => $company->getMorphClass(),
        ]);

        $template = MarketingTemplate::create([
            'name' => 'Product Announcement',
            'subject' => 'Major Feature Launch',
            'body_html' => '<div>Hi {{contact.first_name}} from {{company.name}}!</div>',
            'category' => 'announcement',
        ]);

        $campaign = Campaign::create([
            'name' => 'Enterprise Launch',
            'subject' => 'Big News Inside',
            'sender_name' => 'Marketing Team',
            'sender_email' => 'news@focal.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        $action = new SendCampaignProofAction;
        $result = $action->execute($campaign, 'reviewer1@focal.test, reviewer2@focal.test', $contact);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['sent_to']);

        Mail::assertSentCount(2);
    }

    public function test_campaign_duplication_creates_clean_draft_replica(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Original Template',
            'subject' => 'Subject',
            'body_html' => '<p>Content</p>',
            'category' => 'standard',
        ]);

        $original = Campaign::create([
            'name' => 'Spring Launch 2026',
            'subject' => 'Spring is Here',
            'preview_text' => 'Check our updates',
            'template_id' => $template->id,
            'sender_name' => 'Launch Team',
            'sender_email' => 'launch@focal.test',
            'status' => CampaignStatus::Sent,
            'sent_at' => now(),
            'delivered_count' => 1500,
            'unique_opens_count' => 450,
            'unique_clicks_count' => 120,
            'utm_campaign' => 'spring-launch-2026',
            'budget' => 2500,
        ]);

        $replica = $original->replicate([
            'sent_at',
            'delivered_count',
            'total_recipients',
            'unique_opens_count',
            'unique_clicks_count',
            'bounces_count',
            'unsubscribes_count',
            'ab_winner_variant',
        ]);
        $replica->name = "Copy of {$original->name}";
        $replica->status = CampaignStatus::Draft;
        $replica->sent_at = null;
        $replica->delivered_count = 0;
        $replica->total_recipients = 0;
        $replica->unique_opens_count = 0;
        $replica->unique_clicks_count = 0;
        $replica->bounces_count = 0;
        $replica->unsubscribes_count = 0;
        $replica->save();

        $this->assertSame('Copy of Spring Launch 2026', $replica->name);
        $this->assertSame(CampaignStatus::Draft, $replica->status);
        $this->assertNull($replica->sent_at);
        $this->assertSame(0, $replica->delivered_count);
        $this->assertSame(0, $replica->unique_opens_count);
        $this->assertSame(0, $replica->unique_clicks_count);
        $this->assertSame($original->template_id, $replica->template_id);
        $this->assertSame($original->utm_campaign, $replica->utm_campaign);
        $this->assertSame(2500.0, (float) $replica->budget);
    }
}
