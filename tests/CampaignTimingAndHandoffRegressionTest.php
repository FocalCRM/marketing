<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\HandoffLeadToSalesAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Sales\Models\Pipeline;

/**
 * Regressions for Carbon 3's signed diffIn*() results and a wrong column name,
 * which went unnoticed because SQLite tolerates both.
 */
class CampaignTimingAndHandoffRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_sweep_sends_due_recipients_and_leaves_future_ones_pending(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create(['name' => 'Launch', 'subject' => 'Launch', 'body_html' => '<p>Hi {{contact.first_name}}</p>']);
        $campaign = Campaign::create([
            'name' => 'Timezone launch',
            'subject' => 'Launch',
            'sender_name' => 'Odden',
            'sender_email' => 'news@example.com',
            'template_id' => $template->id,
            'status' => CampaignStatus::Sending,
            'send_in_recipient_timezone' => true,
        ]);

        $due = $this->recipient($campaign, 'due@example.com', now()->subMinute());
        $later = $this->recipient($campaign, 'later@example.com', now()->addHours(2));

        $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();

        $this->assertSame(RecipientStatus::Sent, $due->fresh()?->status);
        $this->assertSame(RecipientStatus::Pending, $later->fresh()?->status, 'A recipient scheduled two hours ahead must not be sent early.');
    }

    public function test_lead_handoff_creates_the_deal_in_the_pipelines_first_stage(): void
    {
        $pipeline = Pipeline::create(['name' => 'Sales', 'code' => 'sales', 'is_default' => true]);
        // Created out of order, so insertion order and sort order disagree.
        $pipeline->stages()->create(['name' => 'Proposal', 'code' => 'proposal', 'sort_order' => 2, 'probability' => 50]);
        $first = $pipeline->stages()->create(['name' => 'Discovery', 'code' => 'discovery', 'sort_order' => 1, 'probability' => 10]);

        $contact = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

        $result = app(HandoffLeadToSalesAction::class)->execute($contact, pipelineId: $pipeline->id);

        $this->assertNotNull($result['deal']);
        $this->assertSame($first->id, $result['deal']->stage_id);
    }

    private function recipient(Campaign $campaign, string $email, \DateTimeInterface $sendAt): CampaignRecipient
    {
        $contact = Contact::create(['first_name' => 'Test', 'email' => $email]);

        return CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $email,
            'status' => RecipientStatus::Pending,
            'scheduled_send_at' => $sendAt,
        ]);
    }
}
