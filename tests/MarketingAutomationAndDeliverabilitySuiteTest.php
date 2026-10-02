<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Core\Models\CrmList;
use Focal\Marketing\Actions\AuditCampaignDeliverabilityAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Enums\WorkflowStepType;
use Focal\Marketing\Enums\WorkflowTriggerType;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\FormSubmission;
use Focal\Marketing\Models\MarketingForm;
use Focal\Marketing\Models\MarketingTemplate;
use Focal\Marketing\Models\MarketingWorkflow;
use Focal\Marketing\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class MarketingAutomationAndDeliverabilitySuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_workflow_steps_and_visual_journey(): void
    {
        /** @var MarketingWorkflow $workflow */
        $workflow = MarketingWorkflow::create([
            'name' => 'Enterprise Product Nurture Sequence',
            'trigger_type' => WorkflowTriggerType::CustomEvent,
            'trigger_config' => ['event_name' => 'workspace_upgraded'],
            'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_number' => 1,
            'type' => WorkflowStepType::Delay,
            'config' => ['hours' => 48],
        ]);

        WorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_number' => 2,
            'type' => WorkflowStepType::SendEmail,
            'config' => ['template_id' => 101],
        ]);

        WorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_number' => 3,
            'type' => WorkflowStepType::Condition,
            'config' => ['rule' => 'lead_score >= 80'],
            'next_step_on_true' => 4,
            'next_step_on_false' => null,
        ]);

        WorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_number' => 4,
            'type' => WorkflowStepType::CreateSalesTask,
            'config' => ['title' => 'VIP Account Outreach'],
        ]);

        $this->assertCount(4, $workflow->steps);

        // Render Visual Journey view
        $view = view('focal-marketing::workflow-journey', [
            'workflow' => $workflow->load('steps'),
        ])->render();

        $this->assertStringContainsString('Workflow Trigger', $view);
        $this->assertStringContainsString('Custom In-App / Product Event', $view);
        $this->assertStringContainsString('Wait <strong>48 hours</strong>', $view);
        $this->assertStringContainsString('Send Marketing Email', $view);
        $this->assertStringContainsString('Evaluate Condition', $view);
        $this->assertStringContainsString('Create Priority Sales Task', $view);
        $this->assertStringContainsString('Journey Completed', $view);
    }

    public function test_dispatch_scheduled_campaigns_console_command(): void
    {
        $contact = Contact::create([
            'first_name' => 'Grace',
            'email' => 'grace@hopper.test',
            'timezone' => 'America/New_York',
        ]);

        $template = MarketingTemplate::create([
            'name' => 'Scheduled Broadcast Template',
            'subject' => 'Scheduled News',
            'body_html' => '<p>Hello {{contact.first_name}}</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        // 1. Due scheduled campaign (dispatch needs an audience list)
        $list = CrmList::create(['name' => 'Scheduled audience', 'type' => 'static']);
        $list->addMember($contact);

        $campaign = Campaign::create([
            'list_id' => $list->id,
            'name' => 'Matured Scheduled Campaign',
            'subject' => 'Scheduled News',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now()->subMinutes(5),
            'template_id' => $template->id,
        ]);

        // Run artisan console command
        $exitCode = Artisan::call('marketing:dispatch-scheduled');
        $this->assertSame(0, $exitCode);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertGreaterThanOrEqual(1, $campaign->delivered_count);

        // 2. Sending campaign with pending timezone wave. The contact is in New York and the
        // campaign sends at 09:00 local time, so freeze the clock two minutes before.
        $this->travelTo(Carbon::parse('2026-06-15 08:58', 'America/New_York'));

        $tzCampaign = Campaign::create([
            'name' => 'Timezone Wave Campaign',
            'subject' => 'Morning Digest',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'status' => CampaignStatus::Sending,
            'send_in_recipient_timezone' => true,
            'recipient_send_hour' => 9,
            'template_id' => $template->id,
        ]);

        $pendingRecipient = $tzCampaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Pending,
        ]);

        // Run command to sweep timezone waves
        Artisan::call('marketing:dispatch-scheduled');

        $pendingRecipient->refresh();
        $this->assertSame(RecipientStatus::Sent, $pendingRecipient->status);
        $this->assertNotNull($pendingRecipient->sent_at);

        // 3. Hours before the local send window, the recipient must wait.
        $this->travelTo(Carbon::parse('2026-06-16 06:00', 'America/New_York'));

        // A second New York contact: a contact is a recipient of a campaign at most once.
        $earlyContact = Contact::create([
            'first_name' => 'Katherine',
            'email' => 'katherine@johnson.test',
            'timezone' => 'America/New_York',
        ]);

        $earlyRecipient = $tzCampaign->recipients()->create([
            'contact_id' => $earlyContact->id,
            'email' => $earlyContact->email,
            'status' => RecipientStatus::Pending,
        ]);

        Artisan::call('marketing:dispatch-scheduled');

        $this->assertSame(RecipientStatus::Pending, $earlyRecipient->fresh()?->status);
    }

    public function test_pre_flight_deliverability_and_spam_inspector(): void
    {
        $action = new AuditCampaignDeliverabilityAction;

        // 1. Clean compliant corporate campaign
        $cleanTemplate = MarketingTemplate::create([
            'name' => 'Clean Template',
            'subject' => 'Q4 Strategic Overview & Performance Highlights',
            'body_html' => '<p>Dear {{contact.first_name}}, please find our latest performance update attached.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        $cleanCampaign = Campaign::create([
            'name' => 'Clean Campaign',
            'subject' => 'Q4 Strategic Overview & Performance Highlights',
            'sender_name' => 'Focal Enterprise',
            'sender_email' => 'insights@focal.test',
            'template_id' => $cleanTemplate->id,
        ]);

        $cleanAudit = $action->execute($cleanCampaign);
        $this->assertGreaterThanOrEqual(90, $cleanAudit['score']);
        $this->assertSame('Excellent', $cleanAudit['rating']);

        // 2. High-risk campaign: consumer sender, spam phrase, missing unsubscribe, broken merge tag
        $spamTemplate = MarketingTemplate::create([
            'name' => 'Spam Template',
            'subject' => 'URGENT!!! 100% FREE CASH BONUS ACT NOW',
            'body_html' => '<div>Hi {{contact.first_name Congratulations you won!</div>', // missing unsubscribe, unclosed tag
        ]);

        $spamCampaign = Campaign::create([
            'name' => 'Spam Campaign',
            'subject' => 'URGENT!!! 100% FREE CASH BONUS ACT NOW',
            'sender_name' => 'Spam Bot',
            'sender_email' => 'spammer@gmail.com', // consumer domain
            'template_id' => $spamTemplate->id,
        ]);

        $spamAudit = $action->execute($spamCampaign);
        $this->assertLessThan(60, $spamAudit['score']);

        $failedChecks = collect($spamAudit['checks'])->where('passed', false)->pluck('name')->all();
        $this->assertContains('Unsubscribe Link Compliance', $failedChecks);
        $this->assertContains('Merge Tag Syntax', $failedChecks);
        $this->assertContains('Spam Keyword & Subject Hygiene', $failedChecks);
        $this->assertContains('Sender Domain Authentication', $failedChecks);

        // Render modal view
        $view = view('focal-marketing::campaign-deliverability-audit', [
            'audit' => $spamAudit,
            'campaign' => $spamCampaign,
        ])->render();

        $this->assertStringContainsString('Deliverability Health Score', $view);
        $this->assertStringContainsString('Unsubscribe Link Compliance', $view);
    }

    public function test_contact_marketing_touchpoints_relationships(): void
    {
        $contact = Contact::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@babbage.test',
        ]);

        $campaign = Campaign::create([
            'name' => 'Analytical Engine Launch',
            'subject' => 'The Future of Computing',
            'sender_name' => 'Charles Babbage',
            'sender_email' => 'charles@babbage.test',
        ]);

        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
            'sent_at' => now()->subDay(),
            'opened_at' => now()->subHours(12),
            'clicked_at' => now()->subHours(11),
        ]);

        $form = MarketingForm::create([
            'title' => 'Algorithm Consultation',
            'slug' => 'algorithm-consultation',
            'fields_schema' => [],
        ]);

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['notes' => 'Bernoulli numbers computation'],
            'utm_source' => 'linkedin',
        ]);

        // Test dynamic relations resolved on Contact
        $this->assertCount(1, $contact->campaignRecipients);
        $this->assertSame($campaign->id, $contact->campaignRecipients->first()?->campaign_id);
        $this->assertNotNull($contact->campaignRecipients->first()?->opened_at);

        $this->assertCount(1, $contact->formSubmissions);
        $this->assertSame($form->id, $contact->formSubmissions->first()?->form_id);
    }
}
