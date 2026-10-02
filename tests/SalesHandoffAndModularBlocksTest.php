<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\CampaignType;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Services\EmailBlockRenderer;
use Odden\Marketing\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SalesHandoffAndModularBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_loop_sales_handoff_workflow_execution(): void
    {
        $salesRep = User::factory()->create();

        $pipeline = Pipeline::create([
            'name' => 'Enterprise Sales Pipeline',
            'code' => 'enterprise',
            'is_default' => true,
        ]);

        $stage = $pipeline->stages()->create([
            'name' => 'Demo Scheduled',
            'code' => 'demo_scheduled',
            'position' => 1,
            'probability' => 25,
        ]);

        $contact = Contact::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@analyticalengine.org',
            'lifecycle_stage' => LifecycleStage::SalesQualifiedLead,
            'lead_score' => 100,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'MQL to Sales Executive Handoff',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        // Step 1: Assign Sales Rep Owner
        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::AssignOwner,
            'config' => [
                'owner_id' => $salesRep->id,
            ],
        ]);

        // Step 2: Auto-create Deal
        $workflow->steps()->create([
            'step_number' => 2,
            'type' => WorkflowStepType::CreateDeal,
            'config' => [
                'deal_name' => 'Enterprise Pilot - Ada Lovelace',
                'amount' => 35000.00,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
            ],
        ]);

        // Step 3: Priority Sales SLA Task
        $workflow->steps()->create([
            'step_number' => 3,
            'type' => WorkflowStepType::CreateSalesTask,
            'config' => [
                'title' => '2-Hour Outreach SLA: Executive Demo Call',
                'due_in_hours' => 2,
            ],
        ]);

        // Step 4: Internal Notification
        $workflow->steps()->create([
            'step_number' => 4,
            'type' => WorkflowStepType::InternalNotification,
            'config' => [
                'message' => 'High-value Enterprise Pilot Deal provisioned for Ada Lovelace.',
            ],
        ]);

        $enrollAction = app(EnrollContactInWorkflowAction::class);
        $enrollment = $enrollAction->execute($workflow, $contact);

        $this->assertNotNull($enrollment);

        // 1. Verify owner assigned
        $contact->refresh();
        $this->assertSame($salesRep->id, $contact->owner_id);

        // 2. Verify Deal created and associated with contact
        /** @var Deal|null $deal */
        $deal = Deal::where('name', 'Enterprise Pilot - Ada Lovelace')->first();
        $this->assertNotNull($deal);
        $this->assertSame(35000.0, (float) $deal->amount);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertTrue($contact->isAssociatedWith($deal));

        // 3. Verify Sales Task and Internal Alert on activity timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => '2-Hour Outreach SLA: Executive Demo Call',
        ]);

        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => 'Internal Alert: High-value Enterprise Pilot Deal provisioned for Ada Lovelace.',
        ]);
    }

    public function test_modular_email_block_renderer_produces_bulletproof_html(): void
    {
        $renderer = new EmailBlockRenderer;

        $blocks = [
            [
                'type' => 'hero',
                'title' => 'Next-Gen Marketing Intelligence',
                'subtitle' => 'Empowering modern revenue teams with autonomous AI.',
                'button_text' => 'Get Started Today',
                'button_url' => 'https://odden.test/trial',
            ],
            [
                'type' => 'columns',
                'left_title' => 'Multi-Touch Attribution',
                'left_body' => 'See exact revenue influence across First, Last, and Linear touch models.',
                'right_title' => 'Omnichannel SMS',
                'right_body' => 'Coordinate email, SMS, and WhatsApp journeys seamlessly.',
            ],
            [
                'type' => 'features',
                'items' => [
                    ['icon' => '🚀', 'title' => 'Rapid Deployment', 'text' => 'Set up in minutes.'],
                    ['icon' => '🔒', 'title' => 'Zero Lock-in', 'text' => 'Own your database and data models.'],
                ],
            ],
            [
                'type' => 'testimonial',
                'quote' => 'Odden transformed our marketing ROI in 30 days.',
                'author' => 'Jane Doe',
                'role' => 'CMO',
                'company' => 'Acme Labs',
            ],
            [
                'type' => 'cta',
                'heading' => 'Upgrade your CRM now',
                'text' => 'Free 14-day trial for high-growth startups.',
                'button_text' => 'Claim Your Seat',
                'button_url' => 'https://odden.test/signup',
            ],
            [
                'type' => 'footer',
                'company_name' => 'Odden HQ',
                'address' => 'San Francisco, CA',
            ],
        ];

        $html = $renderer->render($blocks);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Next-Gen Marketing Intelligence', $html);
        $this->assertStringContainsString('Get Started Today', $html);
        $this->assertStringContainsString('Multi-Touch Attribution', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Claim Your Seat', $html);
        $this->assertStringContainsString('Unsubscribe or manage your email preferences', $html);

        // Test preset rendering
        $presetHtml = $renderer->renderPreset('product_launch');
        $this->assertStringContainsString('Introducing Odden 2.0 🚀', $presetHtml);
        $this->assertStringContainsString('Sarah Connor', $presetHtml);
    }

    public function test_campaign_targets_and_pipeline_forecasting(): void
    {
        $campaign = Campaign::create([
            'name' => 'Q4 Enterprise AI Launch',
            'subject' => 'The Future of Autonomous CRM',
            'status' => CampaignStatus::Draft,
            'type' => CampaignType::Regular,
            'sender_name' => 'Odden Marketing',
            'sender_email' => 'newsletter@odden.test',
            'target_leads' => 200,
            'target_pipeline' => 150000.00,
            'target_revenue' => 50000.00,
            'unique_clicks_count' => 120,
        ]);

        $this->assertSame(200, $campaign->target_leads);
        $this->assertSame(150000.0, (float) $campaign->target_pipeline);
        $this->assertSame(50000.0, (float) $campaign->target_revenue);

        // 120 / 200 * 100 = 60.0%
        $this->assertSame(60.0, $campaign->leads_progress_percentage);
    }
}
