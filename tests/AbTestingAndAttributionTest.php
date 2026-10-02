<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Actions\EvaluateAbTestWinnerAction;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class AbTestingAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ab_split_test_dispatch_splits_sample_and_stages_remaining_audience(): void
    {
        $contacts = new Collection;
        for ($i = 1; $i <= 10; $i++) {
            $contacts->push(Contact::create([
                'first_name' => "User{$i}",
                'last_name' => 'Test',
                'email' => "user{$i}@company.test",
            ]));
        }

        $campaign = Campaign::create([
            'name' => 'SaaS Re-engagement Blast',
            'subject' => 'Variant A: We miss you!',
            'sender_name' => 'Odden Marketing',
            'sender_email' => 'blast@odden.test',
            'is_ab_test' => true,
            'variant_b_subject' => 'Variant B: See what is new this month',
            'ab_test_sample_percentage' => 40, // 40% of 10 = 4 contacts test sample
            'ab_test_duration_hours' => 4,
            'ab_winning_metric' => 'click_rate',
        ]);

        $action = new DispatchCampaignAction;
        $result = $action->execute($campaign, $contacts);

        $this->assertSame(10, $result['total_recipients']);
        $this->assertSame(4, $result['delivered_count']); // 2 Variant A + 2 Variant B
        $this->assertSame(0, $result['suppressed_count']);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Sending, $campaign->status);

        // Variant A recipients
        $variantARecipients = $campaign->recipients()->where('variant', 'A')->get();
        $this->assertCount(2, $variantARecipients);
        $this->assertTrue($variantARecipients->every(fn (CampaignRecipient $r) => $r->status === RecipientStatus::Sent));

        // Variant B recipients
        $variantBRecipients = $campaign->recipients()->where('variant', 'B')->get();
        $this->assertCount(2, $variantBRecipients);
        $this->assertTrue($variantBRecipients->every(fn (CampaignRecipient $r) => $r->status === RecipientStatus::Sent));

        // Staged pending recipients (10 - 4 = 6)
        $pendingRecipients = $campaign->recipients()->where('status', RecipientStatus::Pending->value)->get();
        $this->assertCount(6, $pendingRecipients);
        $this->assertTrue($pendingRecipients->every(fn (CampaignRecipient $r) => $r->variant === null));
    }

    public function test_evaluate_ab_test_winner_picks_winning_variant_and_rolls_out(): void
    {
        $contacts = new Collection;
        for ($i = 1; $i <= 10; $i++) {
            $contacts->push(Contact::create([
                'first_name' => "User{$i}",
                'last_name' => 'Test',
                'email' => "lead{$i}@test.com",
            ]));
        }

        $campaign = Campaign::create([
            'name' => 'Quarterly Feature Announcement',
            'subject' => 'Variant A Subject',
            'sender_name' => 'Odden',
            'sender_email' => 'hello@odden.test',
            'is_ab_test' => true,
            'variant_b_subject' => 'Variant B Subject',
            'ab_test_sample_percentage' => 40,
            'ab_winning_metric' => 'click_rate',
        ]);

        $dispatchAction = new DispatchCampaignAction;
        $dispatchAction->execute($campaign, $contacts);

        // Simulate Variant B getting 2 clicks and Variant A getting 0 clicks
        /** @var CampaignRecipient $recB1 */
        $recB1 = $campaign->recipients()->where('variant', 'B')->first();
        $recB1->recordClick('https://odden.test/pricing');

        /** @var CampaignRecipient $recB2 */
        $recB2 = $campaign->recipients()->where('variant', 'B')->skip(1)->first();
        $recB2->recordClick('https://odden.test/demo');

        // Evaluate A/B test winner
        $evalAction = new EvaluateAbTestWinnerAction;
        $evalResult = $evalAction->execute($campaign);

        $this->assertSame('B', $evalResult['winner']);
        $this->assertSame('click_rate', $evalResult['metric']);
        $this->assertSame(0.0, $evalResult['variant_a_score']);
        $this->assertSame(100.0, $evalResult['variant_b_score']);
        $this->assertSame(6, $evalResult['remaining_sent']);

        $campaign->refresh();
        $this->assertSame('B', $campaign->ab_winner_variant);
        $this->assertNotNull($campaign->ab_test_evaluated_at);
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertSame(10, $campaign->delivered_count);

        // Check that all 6 remaining recipients have received Variant B (2 sample clicked + 6 rolled out)
        $allBRecipients = $campaign->recipients()->where('variant', 'B')->get();
        $this->assertCount(8, $allBRecipients); // 2 sample + 6 rolled out
        $this->assertSame(6, $allBRecipients->where('status', RecipientStatus::Sent)->count());
        $this->assertSame(2, $allBRecipients->where('status', RecipientStatus::Clicked)->count());

        // No more pending recipients
        $this->assertSame(0, $campaign->recipients()->where('status', RecipientStatus::Pending->value)->count());
    }

    public function test_artisan_evaluate_ab_tests_command_processes_mature_campaign(): void
    {
        $contact1 = Contact::create(['first_name' => 'Alice', 'email' => 'alice@test.com']);
        $contact2 = Contact::create(['first_name' => 'Bob', 'email' => 'bob@test.com']);
        $contact3 = Contact::create(['first_name' => 'Charlie', 'email' => 'charlie@test.com']);
        $contact4 = Contact::create(['first_name' => 'Diana', 'email' => 'diana@test.com']);

        $campaign = Campaign::create([
            'name' => 'Automated Timer Campaign',
            'subject' => 'Variant A',
            'sender_name' => 'Odden',
            'sender_email' => 'odden@test.com',
            'is_ab_test' => true,
            'variant_b_subject' => 'Variant B',
            'ab_test_sample_percentage' => 50,
            'ab_test_duration_hours' => 2,
            'status' => CampaignStatus::Draft,
        ]);

        $dispatchAction = new DispatchCampaignAction;
        $dispatchAction->execute($campaign, collect([$contact1, $contact2, $contact3, $contact4]));

        // Age campaign sent_at to 3 hours ago (duration was 2 hours)
        $campaign->update(['sent_at' => now()->subHours(3)]);

        $this->artisan('marketing:evaluate-ab-tests')
            ->expectsOutputToContain("Campaign #{$campaign->id}")
            ->expectsOutputToContain('Evaluated 1 A/B campaign(s).')
            ->assertExitCode(0);

        $campaign->refresh();
        $this->assertNotNull($campaign->ab_winner_variant);
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
    }

    public function test_utm_multi_touch_campaign_attribution_calculates_leads_and_won_revenue(): void
    {
        $contact = Contact::create([
            'first_name' => 'Sundar',
            'last_name' => 'Pichai',
            'email' => 'sundar@alphabet.test',
        ]);

        $campaign = Campaign::create([
            'name' => 'Cloud Next 2026',
            'subject' => 'Announcing Next Generation Cloud Infrastructure',
            'sender_name' => 'Cloud Team',
            'sender_email' => 'cloud@alphabet.test',
        ]);

        // 1. Contact submitted form with matching UTM campaign slug
        $form = MarketingForm::create([
            'title' => 'VIP Registration',
            'slug' => 'vip-registration',
            'fields_schema' => [],
        ]);

        FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['email' => $contact->email],
            'utm_campaign' => 'cloud-next-2026',
            'utm_source' => 'linkedin',
            'utm_medium' => 'cpc',
        ]);

        // 2. Contact clicked email campaign
        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
        ]);
        $recipient->recordClick('https://cloud.alphabet.test/vip');

        // 3. Create Won Deal and associate with Contact
        $pipeline = Pipeline::create(['name' => 'Enterprise Sales', 'code' => 'enterprise-sales', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed-won', 'probability' => 100, 'sort_order' => 1, 'is_closed_won' => true]);

        $deal = Deal::create([
            'name' => 'Alphabet Enterprise Contract',
            'amount' => 125000.00,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => DealStatus::Won,
            'won_at' => now(),
        ]);

        $contact->associateWith($deal);

        // Also create an open pipeline deal
        $openStage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Negotiation', 'code' => 'negotiation', 'probability' => 60, 'sort_order' => 2]);
        $openDeal = Deal::create([
            'name' => 'Cloud Addon Services',
            'amount' => 25000.00,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $openStage->id,
            'status' => DealStatus::Open,
        ]);
        $contact->associateWith($openDeal);

        // 4. Calculate attribution
        $action = new GetCampaignAttributionAction;
        $attribution = $action->execute($campaign);

        $this->assertSame('Cloud Next 2026', $attribution['campaign_name']);
        $this->assertSame(1, $attribution['leads_count']);
        $this->assertSame(1, $attribution['engaged_contacts_count']);
        $this->assertSame(2, $attribution['deals_count']);
        $this->assertSame(25000.00, $attribution['pipeline_value']);
        $this->assertSame(125000.00, $attribution['won_revenue']);
    }
}
