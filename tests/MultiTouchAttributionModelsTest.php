<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\CalculateClosedLoopMetricsAction;
use Focal\Marketing\Actions\GetCampaignAttributionAction;
use Focal\Marketing\Enums\AttributionModel;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\FormSubmission;
use Focal\Marketing\Models\MarketingForm;
use Focal\Sales\Enums\DealStatus;
use Focal\Sales\Models\Deal;
use Focal\Sales\Models\Pipeline;
use Focal\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiTouchAttributionModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_u_shaped_and_time_decay_attribution_models(): void
    {
        $contact = Contact::create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@navy.mil',
        ]);

        $campaign = Campaign::create([
            'name' => 'Compiler Revolution',
            'subject' => 'The Future of COBOL',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'budget' => 10000.00,
            'actual_spend' => 5000.00,
        ]);

        $form = MarketingForm::create([
            'title' => 'Compiler Whitepaper',
            'slug' => 'compiler-whitepaper',
            'fields_schema' => [],
        ]);

        FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['email' => $contact->email],
            'utm_campaign' => 'compiler-revolution',
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_hop_1',
            'unsubscribe_token' => 'unsub_hop_1',
            'opened_at' => now()->subDays(2),
        ]);

        $pipeline = Pipeline::create(['name' => 'Govt Tech', 'code' => 'govt_tech']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'order' => 1]);

        $deal = Deal::create([
            'name' => 'DoD Compiler Deployment',
            'amount' => 100000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $contact->associateWith($deal);

        $action = new GetCampaignAttributionAction;

        // U-Shaped (80% weighted credit)
        $uShaped = $action->execute($campaign, AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uShaped['attribution_model']);
        $this->assertEquals(80000.00, $uShaped['attributed_won_revenue']);

        // Time-Decay (65% weighted credit)
        $timeDecay = $action->execute($campaign, AttributionModel::TimeDecay);
        $this->assertSame('time_decay', $timeDecay['attribution_model']);
        $this->assertEquals(65000.00, $timeDecay['attributed_won_revenue']);
    }

    public function test_calculate_closed_loop_metrics_with_u_shaped_and_w_shaped_models(): void
    {
        $contact = Contact::create([
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'turing@bletchley.uk',
        ]);

        $campaign = Campaign::create([
            'name' => 'Enigma Analytics Briefing',
            'subject' => 'Decoding Data',
            'sender_name' => 'Focal',
            'sender_email' => 'intel@focal.test',
            'actual_spend' => 10000.00,
            'delivered_count' => 1,
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_tur_1',
            'unsubscribe_token' => 'unsub_tur_1',
        ]);

        $pipeline = Pipeline::create(['name' => 'Defense', 'code' => 'defense']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'order' => 1]);

        $deal = Deal::create([
            'name' => 'Bletchley Park Analysis Contract',
            'amount' => 50000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $associationsTable = config('focal-core.tables.associations', 'focal_associations');
        DB::table($associationsTable)->insert([
            'parent_type' => (new Contact)->getMorphClass(),
            'parent_id' => $contact->id,
            'child_type' => (new Deal)->getMorphClass(),
            'child_id' => $deal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $action = new CalculateClosedLoopMetricsAction;

        // U-Shaped (80% attribution)
        $uMetrics = $action->execute(AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uMetrics['attribution_model']);
        $this->assertEquals(50000.00, $uMetrics['total_closed_won_revenue']);
        $this->assertEquals(40000.00, $uMetrics['attributed_closed_won_revenue']); // 50000 * 0.80

        // W-Shaped (70% attribution)
        $wMetrics = $action->execute(AttributionModel::WShaped);
        $this->assertSame('w_shaped', $wMetrics['attribution_model']);
        $this->assertEquals(35000.00, $wMetrics['attributed_closed_won_revenue']); // 50000 * 0.70
    }
}
