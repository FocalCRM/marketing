<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\ApplyLeadScoringEventAction;
use Focal\Marketing\Actions\ProcessFormSubmissionAction;
use Focal\Marketing\Enums\LeadScoringEventType;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\LeadScoringRule;
use Focal\Marketing\Models\MarketingForm;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeadScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_scoring_updates_contact_score_and_promotes_lifecycle_stage(): void
    {
        $contact = Contact::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 0,
        ]);

        $action = new ApplyLeadScoringEventAction;

        // 1. Form submission (+15 pts default)
        $action->execute($contact, LeadScoringEventType::FormSubmission, 'Submitted enterprise inquiry form');
        $contact->refresh();
        $this->assertSame(15, $contact->lead_score);
        $this->assertSame(LifecycleStage::Lead, $contact->lifecycle_stage);

        // 2. Add custom scoring rule (+40 pts for high-intent webinar attendance)
        LeadScoringRule::create([
            'name' => 'Webinar Attendance',
            'event_type' => LeadScoringEventType::PropertyMatch,
            'score_change' => 40,
            'is_active' => true,
        ]);

        $action->execute($contact, LeadScoringEventType::PropertyMatch, 'Attended live product demo');
        $contact->refresh();
        $this->assertSame(55, $contact->lead_score); // 15 + 40 = 55
        // Reached >= 50: Auto-promoted to MQL!
        $this->assertSame(LifecycleStage::MarketingQualifiedLead, $contact->lifecycle_stage);

        // 3. Link clicked in email (+10 pts) -> 65 pts
        $action->execute($contact, LeadScoringEventType::EmailClicked, 'Clicked pricing page link');
        $contact->refresh();
        $this->assertSame(65, $contact->lead_score);

        // 4. Reach >= 100 points via custom rule -> Auto-promoted to SQL!
        LeadScoringRule::create([
            'name' => 'Budget Approved',
            'event_type' => LeadScoringEventType::FormSubmission,
            'score_change' => 45,
            'is_active' => true,
        ]);

        $action->execute($contact, LeadScoringEventType::FormSubmission, 'Requested formal quotation');
        $contact->refresh();
        $this->assertSame(110, $contact->lead_score); // 65 + 45 = 110
        $this->assertSame(LifecycleStage::SalesQualifiedLead, $contact->lifecycle_stage);

        // Verify score audit logs were created
        $this->assertCount(4, $contact->leadScoreLogs);
    }

    public function test_form_submission_automatically_triggers_lead_scoring(): void
    {
        $form = MarketingForm::create([
            'title' => 'Download Whitepaper',
            'slug' => 'download-whitepaper',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
            ],
        ]);

        $action = new ProcessFormSubmissionAction;
        $submission = $action->execute($form, [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@navy.mil',
        ]);

        $contact = $submission->contact;
        $this->assertNotNull($contact);
        $this->assertSame(15, $contact->lead_score);
        $this->assertNotNull($contact->lead_score_updated_at);
        $this->assertDatabaseHas('focal_marketing_lead_score_logs', [
            'contact_id' => $contact->id,
            'event_type' => LeadScoringEventType::FormSubmission->value,
            'score_change' => 15,
        ]);
    }

    public function test_tracking_pixel_and_clicks_trigger_lead_scoring(): void
    {
        $contact = Contact::create([
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'alan@bletchley.test',
            'lead_score' => 10,
        ]);

        $campaign = Campaign::create([
            'name' => 'Q4 Security Update',
            'subject' => 'Security Patches',
            'sender_name' => 'Focal',
            'sender_email' => 'updates@focal.test',
        ]);

        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
        ]);

        // 1. Trigger open pixel -> +3 points
        $this->get('/marketing/track/open/'.$recipient->tracking_token);
        $contact->refresh();
        $this->assertSame(13, $contact->lead_score);

        // 2. Trigger click redirect -> +10 points
        $this->get($recipient->getClickRedirectUrl('https://focal.test/security'));
        $contact->refresh();
        $this->assertSame(23, $contact->lead_score);

        // 3. Trigger unsubscribe -> -50 points (clamped to 0 minimum)
        $this->post('/marketing/unsubscribe/'.$recipient->unsubscribe_token);
        $contact->refresh();
        $this->assertSame(0, $contact->lead_score);
    }
}
