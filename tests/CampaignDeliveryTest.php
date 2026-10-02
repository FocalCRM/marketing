<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Core\Models\CrmList;
use Focal\Marketing\Actions\DeliverCampaignMessageAction;
use Focal\Marketing\Actions\DispatchCampaignAction;
use Focal\Marketing\Actions\EvaluateAbTestWinnerAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Exceptions\CampaignHasNoAudienceException;
use Focal\Marketing\Mail\MarketingMessageMailable;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Campaign delivery (#1, #8): every send path queues one message per recipient,
 * exactly once, and only to an explicit audience.
 */
class CampaignDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_queues_one_message_per_eligible_recipient_with_compliance_headers(): void
    {
        Mail::fake();
        config(['focal-marketing.mail.queue' => 'marketing-mail', 'focal-marketing.mail.mailer' => 'array']);

        [$campaign, $list] = $this->campaignWithList(['ada@example.com', 'grace@example.com', 'gone@example.com']);
        MarketingSubscription::unsubscribe('gone@example.com');

        $result = app(DispatchCampaignAction::class)->execute($campaign);

        $this->assertSame(2, $result['delivered_count']);
        $this->assertSame(1, $result['suppressed_count']);
        Mail::assertQueuedCount(2);
        Mail::assertNotQueued(MarketingMessageMailable::class, fn (MarketingMessageMailable $mail): bool => $mail->hasTo('gone@example.com'));

        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame(RecipientStatus::Sent, $recipient->status);
        $this->assertNotNull($recipient->sent_at);

        Mail::assertQueued(MarketingMessageMailable::class, function (MarketingMessageMailable $mail) use ($recipient): bool {
            if (! $mail->hasTo('ada@example.com')) {
                return false;
            }

            $headers = $mail->headers()->text;

            $this->assertInstanceOf(ShouldQueue::class, $mail);
            $this->assertSame('marketing-mail', $mail->queue);
            $this->assertSame('array', $mail->mailer);
            $this->assertTrue($mail->hasFrom('news@example.com', 'Focal News'));
            $this->assertTrue($mail->hasReplyTo('replies@example.com'));
            $this->assertTrue($mail->hasSubject('Spring launch'));
            $this->assertStringContainsString('Hello Ada', $mail->htmlBody);
            $this->assertStringContainsString($recipient->getTrackingPixelUrl(), $mail->htmlBody);
            $this->assertStringContainsString('Hello Ada', $mail->textBody);
            $this->assertStringNotContainsString('<p>', $mail->textBody);
            $this->assertSame('<'.$recipient->getOneClickUnsubscribeUrl().'>', $headers['List-Unsubscribe']);
            $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
            $this->assertSame($recipient->tracking_token, $headers['X-Focal-Tracking-Token']);

            return true;
        });
    }

    public function test_the_queued_message_renders_html_and_plain_text_parts(): void
    {
        Mail::fake();
        [$campaign] = $this->campaignWithList(['ada@example.com']);

        app(DispatchCampaignAction::class)->execute($campaign);

        Mail::assertQueued(MarketingMessageMailable::class, function (MarketingMessageMailable $mail): bool {
            $mail->assertSeeInHtml('Hello Ada', false);
            $mail->assertSeeInText('Hello Ada');
            $mail->assertDontSeeInText('<p>', false);

            return true;
        });
    }

    public function test_dispatching_twice_creates_one_recipient_and_sends_once(): void
    {
        Mail::fake();
        [$campaign] = $this->campaignWithList(['ada@example.com', 'grace@example.com']);

        app(DispatchCampaignAction::class)->execute($campaign);
        $second = app(DispatchCampaignAction::class)->execute($campaign->fresh() ?? $campaign);

        $this->assertSame(0, $second['delivered_count']);
        $this->assertSame(2, $campaign->recipients()->count());
        Mail::assertQueuedCount(2);
        $this->assertSame(2, $campaign->fresh()?->delivered_count);
    }

    public function test_a_recipient_row_created_concurrently_does_not_fail_the_dispatch(): void
    {
        Mail::fake();
        [$campaign, , $contacts] = $this->campaignWithList(['ada@example.com']);

        // Another dispatch inserts the same recipient right after this one looked it up and
        // found nothing, before this one inserts it.
        $raced = false;
        $table = (new CampaignRecipient)->getTable();
        DB::listen(function ($query) use (&$raced, $table, $campaign, $contacts): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, $table) || ! str_contains($query->sql, 'contact_id')) {
                return;
            }
            $raced = true;
            DB::table($table)->insert([
                'campaign_id' => $campaign->id,
                'contact_id' => $contacts[0]->id,
                'email' => 'ada@example.com',
                'status' => RecipientStatus::Pending->value,
                'tracking_token' => 'raced-tracking-token',
                'unsubscribe_token' => 'raced-unsubscribe-token',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = app(DispatchCampaignAction::class)->execute($campaign);

        $this->assertTrue($raced);
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame(1, $result['delivered_count']);
        Mail::assertQueuedCount(1);
    }

    public function test_delivering_a_recipient_twice_sends_once(): void
    {
        Mail::fake();
        [$campaign, , $contacts] = $this->campaignWithList(['ada@example.com']);

        $recipient = $campaign->recipients()->create([
            'contact_id' => $contacts[0]->id,
            'email' => 'ada@example.com',
            'status' => RecipientStatus::Pending,
        ]);

        $deliver = app(DeliverCampaignMessageAction::class);
        $this->assertSame(DeliverCampaignMessageAction::QUEUED, $deliver->execute($campaign, $recipient));
        $this->assertSame(DeliverCampaignMessageAction::ALREADY_SENT, $deliver->execute($campaign, $recipient->fresh() ?? $recipient));
        // A stale in-memory copy (a retried job, say) must not send again either.
        $this->assertSame(DeliverCampaignMessageAction::ALREADY_SENT, $deliver->execute($campaign, $recipient));

        Mail::assertQueuedCount(1);
    }

    public function test_a_recipient_is_not_marked_sent_when_queueing_fails(): void
    {
        [$campaign, , $contacts] = $this->campaignWithList(['ada@example.com']);
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contacts[0]->id,
            'email' => 'ada@example.com',
            'status' => RecipientStatus::Pending,
        ]);

        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Queue unavailable'));

        try {
            app(DeliverCampaignMessageAction::class)->execute($campaign, $recipient);
            $this->fail('The queue failure should propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Queue unavailable', $e->getMessage());
        }

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Pending, $recipient->status);
        $this->assertNull($recipient->sent_at);
    }

    public function test_timezone_wave_release_queues_due_recipients_and_skips_new_unsubscribes(): void
    {
        Mail::fake();
        [$campaign, , $contacts] = $this->campaignWithList(['due@example.com', 'optedout@example.com', 'later@example.com']);
        $campaign->update(['status' => CampaignStatus::Sending, 'send_in_recipient_timezone' => true]);

        $due = $this->pending($campaign, $contacts[0], now()->subMinute());
        $optedOut = $this->pending($campaign, $contacts[1], now()->subMinute());
        $later = $this->pending($campaign, $contacts[2], now()->addHours(3));

        // Unsubscribed after the campaign was dispatched, before the local send window.
        MarketingSubscription::unsubscribe('optedout@example.com');

        $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();
        $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(MarketingMessageMailable::class, fn (MarketingMessageMailable $mail): bool => $mail->hasTo('due@example.com'));
        $this->assertSame(RecipientStatus::Sent, $due->fresh()?->status);
        $this->assertSame(RecipientStatus::Suppressed, $optedOut->fresh()?->status);
        $this->assertNull($optedOut->fresh()?->sent_at);
        $this->assertSame(RecipientStatus::Pending, $later->fresh()?->status);
        $this->assertSame(1, $campaign->fresh()?->delivered_count);
    }

    public function test_ab_winner_rollout_queues_the_winning_variant_to_staged_recipients(): void
    {
        Mail::fake();
        $emails = array_map(fn (int $i): string => "user{$i}@example.com", range(1, 10));
        [$campaign] = $this->campaignWithList($emails, [
            'is_ab_test' => true,
            'variant_b_subject' => 'Variant B subject',
            'ab_test_sample_percentage' => 40,
            'ab_winning_metric' => 'click_rate',
        ]);

        app(DispatchCampaignAction::class)->execute($campaign);
        Mail::assertQueuedCount(4);

        $campaign->recipients()->where('variant', 'B')->firstOrFail()->recordClick();

        $result = app(EvaluateAbTestWinnerAction::class)->execute($campaign->fresh() ?? $campaign);

        $this->assertSame('B', $result['winner']);
        $this->assertSame(6, $result['remaining_sent']);
        Mail::assertQueuedCount(10);
        Mail::assertQueued(MarketingMessageMailable::class, fn (MarketingMessageMailable $mail): bool => $mail->hasSubject('Variant B subject'), 8);
    }

    public function test_dispatch_refuses_a_campaign_without_an_audience(): void
    {
        Mail::fake();
        Contact::create(['first_name' => 'Everyone', 'email' => 'everyone@example.com']);

        $campaign = Campaign::create([
            'name' => 'No list',
            'subject' => 'Hello',
            'sender_name' => 'Focal',
            'sender_email' => 'news@example.com',
            'status' => CampaignStatus::Draft,
        ]);

        try {
            app(DispatchCampaignAction::class)->execute($campaign);
            $this->fail('A campaign without a list must not be dispatched.');
        } catch (CampaignHasNoAudienceException) {
            // expected
        }

        Mail::assertNothingQueued();
        $this->assertSame(0, $campaign->recipients()->count());
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()?->status);
    }

    public function test_scheduled_dispatch_skips_a_campaign_without_an_audience_and_reports_it(): void
    {
        Mail::fake();
        Contact::create(['first_name' => 'Everyone', 'email' => 'everyone@example.com']);

        $campaign = Campaign::create([
            'name' => 'Listless',
            'subject' => 'Hello',
            'sender_name' => 'Focal',
            'sender_email' => 'news@example.com',
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('marketing:dispatch-scheduled')
            ->expectsOutputToContain('has no audience')
            ->assertFailed();

        Mail::assertNothingQueued();
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function test_recipients_are_unique_per_campaign_and_contact(): void
    {
        [$campaign, , $contacts] = $this->campaignWithList(['ada@example.com']);

        $campaign->recipients()->create(['contact_id' => $contacts[0]->id, 'email' => 'ada@example.com', 'status' => RecipientStatus::Pending]);

        $this->expectException(UniqueConstraintViolationException::class);

        $campaign->recipients()->create(['contact_id' => $contacts[0]->id, 'email' => 'ada@example.com', 'status' => RecipientStatus::Pending]);
    }

    /**
     * @param  list<string>  $emails
     * @param  array<string, mixed>  $attributes
     * @return array{0: Campaign, 1: CrmList, 2: list<Contact>}
     */
    public function test_unique_index_migration_keeps_the_most_engaged_duplicate_and_detaches_sent_ones(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_01_04_000018_add_unique_campaign_contact_to_focal_marketing_campaign_recipients.php';
        $migration->down();

        [$campaign, , $contacts] = $this->campaignWithList(['ada@example.com']);
        $recipient = fn (array $attributes): CampaignRecipient => CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contacts[0]->id,
            'email' => 'ada@example.com',
            'status' => RecipientStatus::Pending,
            ...$attributes,
        ]);

        $unsent = $recipient([]);
        $opened = $recipient(['sent_at' => now(), 'opened_at' => now()]);
        $sent = $recipient(['sent_at' => now()]);

        foreach ([$unsent, $sent] as $row) {
            DB::table('focal_marketing_esp_events')->insert([
                'provider' => 'generic',
                'event_type' => 'delivered',
                'email' => 'ada@example.com',
                'campaign_id' => $campaign->id,
                'recipient_id' => $row->id,
            ]);
        }

        $migration->up();

        // The most engaged row stays attached; the other sent row is detached so the links in
        // its email keep working; the unsent row is deleted and its events move to the kept one.
        $this->assertSame([$opened->id], CampaignRecipient::query()->where('contact_id', $contacts[0]->id)->pluck('id')->all());
        $this->assertNull($sent->fresh()?->contact_id);
        $this->assertNotNull($sent->fresh());
        $this->assertNull(CampaignRecipient::find($unsent->id));
        $this->assertEqualsCanonicalizing([$opened->id, $sent->id], DB::table('focal_marketing_esp_events')->pluck('recipient_id')->all());
        $this->post(route('focal.marketing.unsubscribe.process', $sent->unsubscribe_token))->assertOk();

        $this->expectException(UniqueConstraintViolationException::class);
        $recipient([]);
    }

    private function campaignWithList(array $emails, array $attributes = []): array
    {
        $list = CrmList::create(['name' => 'Audience', 'type' => 'static']);
        $contacts = [];

        foreach ($emails as $email) {
            $contact = Contact::create(['first_name' => ucfirst((string) strtok($email, '@')), 'email' => $email]);
            $list->addMember($contact);
            $contacts[] = $contact;
        }

        $template = MarketingTemplate::create([
            'name' => 'Launch',
            'subject' => 'Launch',
            'body_html' => '<html><head><style>p { color: red; }</style></head><body><p>Hello {{contact.first_name}}</p><p><a href="https://example.com/launch">Read more</a></p></body></html>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Spring launch',
            'subject' => 'Spring launch',
            'sender_name' => 'Focal News',
            'sender_email' => 'news@example.com',
            'reply_to_email' => 'replies@example.com',
            'template_id' => $template->id,
            'list_id' => $list->id,
            'status' => CampaignStatus::Draft,
            ...$attributes,
        ]);

        return [$campaign, $list, $contacts];
    }

    private function pending(Campaign $campaign, Contact $contact, \DateTimeInterface $sendAt): CampaignRecipient
    {
        return $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Pending,
            'scheduled_send_at' => $sendAt,
        ]);
    }
}
