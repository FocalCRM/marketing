<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Enums\ListType;
use Focal\Core\Models\Contact;
use Focal\Core\Models\CrmList;
use Focal\Marketing\Actions\DispatchCampaignAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AudienceSegmentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_targets_dynamic_smart_list_and_automatically_syncs_active_members(): void
    {
        // 1. Create Contacts with varying lead scores
        $lead1 = Contact::create([
            'first_name' => 'High',
            'last_name' => 'Score',
            'email' => 'high@enterprise.test',
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
            'lead_score' => 85,
        ]);

        $lead2 = Contact::create([
            'first_name' => 'Mid',
            'last_name' => 'Score',
            'email' => 'mid@enterprise.test',
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
            'lead_score' => 55,
        ]);

        $lead3 = Contact::create([
            'first_name' => 'Low',
            'last_name' => 'Score',
            'email' => 'low@casual.test',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 15,
        ]);

        // 2. Create Dynamic Smart List (Lead Score >= 50)
        $smartList = CrmList::create([
            'name' => 'MQL High-Intent Audience',
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [
                [
                    'property' => 'lead_score',
                    'operator' => '>=',
                    'value' => 50,
                ],
            ],
        ]);

        // 3. Create Campaign targeted at this Smart List
        $campaign = Campaign::create([
            'name' => 'Enterprise VIP Invitation',
            'subject' => 'Exclusive VIP Roundtable Invitation',
            'sender_name' => 'Focal Sales',
            'sender_email' => 'vip@focal.test',
            'crm_list_id' => $smartList->id,
            'status' => CampaignStatus::Draft,
        ]);

        // 4. Dispatch Campaign - should automatically evaluate and sync smart list!
        $action = new DispatchCampaignAction;
        $result = $action->execute($campaign);

        $this->assertSame(2, $result['total_recipients']);
        $this->assertSame(2, $result['delivered_count']);
        $this->assertSame(0, $result['suppressed_count']);

        // Check recipients
        $this->assertDatabaseHas('focal_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $lead1->id,
            'status' => RecipientStatus::Sent->value,
        ]);

        $this->assertDatabaseHas('focal_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $lead2->id,
            'status' => RecipientStatus::Sent->value,
        ]);

        $this->assertDatabaseMissing('focal_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $lead3->id,
        ]);
    }

    public function test_unsubscribed_contacts_in_dynamic_audience_are_suppressed(): void
    {
        $contactActive = Contact::create([
            'first_name' => 'Active',
            'last_name' => 'Subscriber',
            'email' => 'active@focal.test',
            'lead_score' => 70,
        ]);

        $contactOptedOut = Contact::create([
            'first_name' => 'Opted',
            'last_name' => 'Out',
            'email' => 'optout@focal.test',
            'lead_score' => 90,
        ]);

        // Explicitly suppress opted out contact
        MarketingSubscription::unsubscribe('optout@focal.test', $contactOptedOut->id);

        $smartList = CrmList::create([
            'name' => 'All Qualified Leads',
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [
                ['property' => 'lead_score', 'operator' => '>=', 'value' => 50],
            ],
        ]);

        $campaign = Campaign::create([
            'name' => 'Product Feature Webinar',
            'subject' => 'See New Features Live',
            'sender_name' => 'Focal Product',
            'sender_email' => 'product@focal.test',
            'crm_list_id' => $smartList->id,
        ]);

        $action = new DispatchCampaignAction;
        $result = $action->execute($campaign);

        $this->assertSame(2, $result['total_recipients']);
        $this->assertSame(1, $result['delivered_count']);
        $this->assertSame(1, $result['suppressed_count']);

        // Check delivery to active subscriber
        $this->assertDatabaseHas('focal_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $contactActive->id,
            'status' => RecipientStatus::Sent->value,
        ]);

        // Check that opted out contact was NOT sent
        $this->assertDatabaseMissing('focal_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $contactOptedOut->id,
            'status' => RecipientStatus::Sent->value,
        ]);
    }

    public function test_dynamic_smart_list_membership_updates_dynamically(): void
    {
        $contact = Contact::create([
            'first_name' => 'Dynamic',
            'last_name' => 'Lead',
            'email' => 'dynamic@lead.test',
            'lead_score' => 10,
        ]);

        $smartList = CrmList::create([
            'name' => 'Threshold 50 List',
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [
                ['property' => 'lead_score', 'operator' => '>=', 'value' => 50],
            ],
        ]);

        // Initially not matching
        $count = $smartList->syncActiveMembers();
        $this->assertSame(0, $count);
        $this->assertFalse($smartList->hasMember($contact));

        // Lead score increases to 60 -> becomes a member
        $contact->update(['lead_score' => 60]);
        $count = $smartList->syncActiveMembers();
        $this->assertSame(1, $count);
        $this->assertTrue($smartList->hasMember($contact));

        // Lead score decreases to 40 -> removed from membership
        $contact->update(['lead_score' => 40]);
        $count = $smartList->syncActiveMembers();
        $this->assertSame(0, $count);
        $this->assertFalse($smartList->hasMember($contact));
    }
}
