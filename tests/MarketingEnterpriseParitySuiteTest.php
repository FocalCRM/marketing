<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Actions\AutoMatchLeadToCompanyAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Actions\EvaluateSmartContentBlocksAction;
use Odden\Marketing\Actions\ProcessFormSubmissionAction;
use Odden\Marketing\Actions\SyncAdAudienceAction;
use Odden\Marketing\Models\AdAudienceSync;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Tests\Fixtures\User;

class MarketingEnterpriseParitySuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_dynamic_content_blocks(): void
    {
        $tier1Company = Company::create([
            'name' => 'Figma Inc',
            'domain' => 'figma.com',
            'account_tier' => 'tier_1',
        ]);

        $tier1Contact = Contact::create([
            'first_name' => 'Dylan',
            'last_name' => 'Field',
            'email' => 'dylan@figma.com',
            'lifecycle_stage' => LifecycleStage::SalesQualifiedLead,
        ]);
        $tier1Contact->associateWith($tier1Company);

        $customerContact = Contact::create([
            'first_name' => 'Alex',
            'email' => 'alex@customer.test',
            'lifecycle_stage' => LifecycleStage::Customer,
        ]);

        $leadContact = Contact::create([
            'first_name' => 'Sam',
            'email' => 'sam@prospect.test',
            'lifecycle_stage' => LifecycleStage::Lead,
        ]);

        $templateHtml = '<div>Header</div>'
            .'[smart tier="tier_1"]<div class="vip-box">Exclusive Executive Briefing for Tier 1 Enterprise</div>[/smart]'
            .'[smart stage="customer"]<div class="customer-box">Check out our new advanced add-ons in your workspace.</div>[/smart]'
            .'[smart default]<div class="standard-box">Start your 14-day free trial now.</div>[/smart]'
            .'<div>Footer</div>';

        $action = new EvaluateSmartContentBlocksAction;

        // 1. Dylan (Tier 1 company)
        $renderedTier1 = $action->execute($templateHtml, $tier1Contact);
        $this->assertStringContainsString('Exclusive Executive Briefing for Tier 1 Enterprise', $renderedTier1);
        $this->assertStringNotContainsString('Check out our new advanced add-ons', $renderedTier1);
        $this->assertStringNotContainsString('Start your 14-day free trial now', $renderedTier1);

        // 2. Alex (Customer)
        $renderedCustomer = $action->execute($templateHtml, $customerContact);
        $this->assertStringContainsString('Check out our new advanced add-ons in your workspace.', $renderedCustomer);
        $this->assertStringNotContainsString('Exclusive Executive Briefing', $renderedCustomer);
        $this->assertStringNotContainsString('Start your 14-day free trial now', $renderedCustomer);

        // 3. Sam (Standard Lead)
        $renderedLead = $action->execute($templateHtml, $leadContact);
        $this->assertStringContainsString('Start your 14-day free trial now.', $renderedLead);
        $this->assertStringNotContainsString('Exclusive Executive Briefing', $renderedLead);
        $this->assertStringNotContainsString('Check out our new advanced add-ons', $renderedLead);
    }

    public function test_lead_to_account_domain_auto_matching(): void
    {
        $rep = User::factory()->create(['name' => 'Enterprise Account Executive']);

        $targetCompany = Company::create([
            'name' => 'Datadog Inc',
            'domain' => 'datadoghq.com',
            'account_tier' => 'tier_1',
            'owner_id' => $rep->id,
        ]);

        /** @var MarketingForm $form */
        $form = MarketingForm::create([
            'title' => 'Inbound Whitepaper',
            'slug' => 'whitepaper-download',
            'fields_schema' => [
                ['name' => 'first_name', 'type' => 'text'],
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ]);

        // 1. Corporate lead signs up without specifying company name
        $submissionAction = app(ProcessFormSubmissionAction::class);
        $submission = $submissionAction->execute(
            form: $form,
            data: [
                'first_name' => 'Olivier',
                'email' => 'olivier@datadoghq.com',
            ]
        );

        $contact = $submission->contact;
        $this->assertNotNull($contact);

        // Verified auto-matched to Datadog
        $this->assertTrue($contact->isAssociatedWith($targetCompany));
        // Verified sales rep ownership inherited
        $this->assertSame($rep->id, $contact->owner_id);

        // Verified L2A high-priority alert task created on Company timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $targetCompany->getMorphClass(),
            'subject_id' => $targetCompany->id,
            'title' => "L2A Match: New Lead from Target Account {$targetCompany->name}",
        ]);

        // 2. Free consumer email does not auto-match
        $consumerContact = Contact::create([
            'first_name' => 'John',
            'email' => 'john.doe@gmail.com',
        ]);
        $matchAction = new AutoMatchLeadToCompanyAction;
        $result = $matchAction->execute($consumerContact);
        $this->assertNull($result);
        $this->assertCount(0, $consumerContact->companies);
    }

    public function test_custom_behavioral_events_api(): void
    {
        $contact = Contact::create([
            'first_name' => 'Linus',
            'email' => 'linus@kernel.test',
            'lead_score' => 10,
        ]);

        // Test API Endpoint POST /api/marketing/events/track
        $response = $this->postJson('/api/marketing/events/track', [
            'email' => 'linus@kernel.test',
            'event_name' => 'workspace_upgraded',
            'properties' => [
                'plan' => 'enterprise',
                'seats' => 100,
                'annual_contract_value' => 45000,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'event_name' => 'workspace_upgraded',
                'contact_id' => $contact->id,
            ]);

        // Verify record in odden_custom_behavioral_events
        $this->assertDatabaseHas('odden_custom_behavioral_events', [
            'contact_id' => $contact->id,
            'event_name' => 'workspace_upgraded',
        ]);

        // Verify task logged on timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'title' => 'Custom Event: workspace_upgraded',
        ]);

        // Verify lead scoring event triggered
        $contact->refresh();
        $this->assertGreaterThan(10, $contact->lead_score);
    }

    public function test_recipient_local_timezone_send_scheduling(): void
    {
        $campaign = Campaign::create([
            'name' => 'Global Product Webinar',
            'subject' => 'Join us tomorrow',
            'sender_name' => 'Odden',
            'sender_email' => 'marketing@odden.test',
            'send_in_recipient_timezone' => true,
            'recipient_send_hour' => 9,
        ]);

        $nyContact = Contact::create([
            'first_name' => 'NYC User',
            'email' => 'nyc@test.com',
            'timezone' => 'America/New_York',
        ]);

        $tokyoContact = Contact::create([
            'first_name' => 'Tokyo User',
            'email' => 'tokyo@test.com',
            'timezone' => 'Asia/Tokyo',
        ]);

        $nyTime = $campaign->calculateScheduledTimeForContact($nyContact);
        $tokyoTime = $campaign->calculateScheduledTimeForContact($tokyoContact);

        $this->assertNotNull($nyTime);
        $this->assertNotNull($tokyoTime);

        // Dispatch campaign with recipient timezone mode
        $action = new DispatchCampaignAction;
        $result = $action->execute($campaign, collect([$nyContact, $tokyoContact]));

        $this->assertSame(2, $result['total_recipients']);
        $this->assertDatabaseHas('odden_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'email' => 'nyc@test.com',
        ]);
    }

    public function test_ad_audience_sync_hashing_and_generation(): void
    {
        $list = CrmList::create([
            'name' => 'High Intent ABM Retargeting',
            'type' => 'static',
        ]);

        $c1 = Contact::create(['first_name' => 'Marc', 'email' => 'marc@salesforce.com']);
        $c2 = Contact::create(['first_name' => 'Satya', 'email' => 'satya@microsoft.com']);
        $list->addMember($c1);
        $list->addMember($c2);

        $sync = AdAudienceSync::create([
            'name' => 'LinkedIn Ads: Surging Accounts',
            'platform' => 'linkedin',
            'list_id' => $list->id,
            'audience_id' => 'lnkd_aud_99812',
            'is_active' => true,
        ]);

        $action = new SyncAdAudienceAction;
        $result = $action->execute($sync);

        $this->assertSame('linkedin', $result['platform']);
        $this->assertSame(2, $result['records_synced']);
        $this->assertCount(2, $result['hashed_emails']);
        $this->assertCount(2, $result['hashed_domains']);

        // Check SHA-256 accuracy
        $expectedHash = hash('sha256', 'marc@salesforce.com');
        $this->assertContains($expectedHash, $result['hashed_emails']);
        $this->assertContains(hash('sha256', 'salesforce.com'), $result['hashed_domains']);

        // Check database updated
        $sync->refresh();
        $this->assertSame(2, $sync->records_count);
        $this->assertNotNull($sync->last_synced_at);
    }
}
