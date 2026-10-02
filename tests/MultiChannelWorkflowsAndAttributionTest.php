<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class MultiChannelWorkflowsAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_send_sms_step_executes_when_consent_is_granted(): void
    {
        $contactWithConsent = Contact::create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'hopper@navy.mil',
            'phone' => '+12025550192',
            'sms_consent' => true,
            'sms_consent_at' => now(),
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'SMS Notification Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send SMS Confirmation',
            'type' => WorkflowStepType::SendSms,
            'config' => [
                'message' => 'Odden: Your enterprise account has been provisioned!',
                'requires_consent' => true,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contactWithConsent);

        $this->assertNotNull($enrollment);
        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Dispatched SMS to +12025550192', $log->action_taken);

        // Verify task logged on contact timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contactWithConsent->id,
            'title' => 'Workflow SMS: SMS Notification Flow',
        ]);
    }

    public function test_workflow_send_sms_step_is_skipped_without_consent(): void
    {
        $contactWithoutConsent = Contact::create([
            'first_name' => 'No',
            'last_name' => 'Consent',
            'email' => 'noconsent@example.com',
            'phone' => '+12025550199',
            'sms_consent' => false,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Strict SMS Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send SMS',
            'type' => WorkflowStepType::SendSms,
            'config' => [
                'message' => 'Special promotion!',
                'requires_consent' => true,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contactWithoutConsent);

        $this->assertNotNull($enrollment);
        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('Skipped SMS (No SMS consent)', $log->action_taken);
    }

    public function test_workflow_webhook_step_dispatches_outbound_http_payload(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $contact = Contact::create([
            'first_name' => 'Claude',
            'last_name' => 'Shannon',
            'email' => 'shannon@bell.labs',
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Slack Alert Webhook Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Post to Slack Channel',
            'type' => WorkflowStepType::Webhook,
            'config' => [
                'url' => 'https://hooks.slack.com/services/T00/B00/X00',
                'method' => 'POST',
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contact);

        $this->assertNotNull($enrollment);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/services/T00/B00/X00'
                && $request['contact']['email'] === 'shannon@bell.labs';
        });

        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Webhook POST to https://hooks.slack.com', $log->action_taken);
    }

    public function test_attribution_models_compute_weighted_pipeline_and_revenue(): void
    {
        $contact = Contact::create([
            'first_name' => 'John',
            'last_name' => 'von Neumann',
            'email' => 'jvn@ias.edu',
        ]);

        $campaign = Campaign::create([
            'name' => 'Enterprise Architecture Summit',
            'subject' => 'Summit Invitation',
            'sender_name' => 'Events',
            'sender_email' => 'events@odden.test',
        ]);

        $form = MarketingForm::create([
            'title' => 'Summit Registration',
            'slug' => 'summit-reg',
            'fields_schema' => [],
        ]);

        FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['email' => $contact->email],
            'utm_campaign' => 'enterprise-architecture-summit',
        ]);

        // Create associated deal
        $pipeline = Pipeline::create(['name' => 'Strategic Accounts', 'code' => 'strategic', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed-won', 'probability' => 100, 'sort_order' => 1, 'is_closed_won' => true]);

        $deal = Deal::create([
            'name' => 'IAS Supercomputing Contract',
            'amount' => 100000.00,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => DealStatus::Won,
            'won_at' => now(),
        ]);
        $contact->associateWith($deal);

        $action = new GetCampaignAttributionAction;

        // 1. First-Touch Attribution (100% credit to acquisition campaign)
        $firstTouch = $action->execute($campaign, AttributionModel::FirstTouch);
        $this->assertSame(100000.00, $firstTouch['attributed_won_revenue']);
        $this->assertSame('first_touch', $firstTouch['attribution_model']);

        // 2. Linear Attribution (50% split across multi-touch journey)
        $linear = $action->execute($campaign, AttributionModel::Linear);
        $this->assertSame(50000.00, $linear['attributed_won_revenue']);
        $this->assertSame('linear', $linear['attribution_model']);

        // 3. W-Shaped Attribution (70% weighted contribution)
        $wShaped = $action->execute($campaign, AttributionModel::WShaped);
        $this->assertSame(70000.00, $wShaped['attributed_won_revenue']);
        $this->assertSame('w_shaped', $wShaped['attribution_model']);
    }
}
