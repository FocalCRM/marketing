<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CalculateRecipientOptimalSendTimeAction;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;

class CalculateRecipientOptimalSendTimeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_timezone_from_contact_and_calculates_local_time(): void
    {
        $contact = Contact::create([
            'first_name' => 'London',
            'last_name' => 'User',
            'email' => 'london@example.co.uk',
            'properties' => ['timezone' => 'Europe/London'],
        ]);

        $campaign = new Campaign([
            'name' => 'Global Announcement',
            'scheduled_local_time' => '09:00',
        ]);

        $baseDate = Carbon::parse('2026-10-15 00:00:00', 'UTC');

        $action = new CalculateRecipientOptimalSendTimeAction;
        $sendAt = $action->execute($campaign, $contact, $baseDate);

        // 9:00 AM British Summer Time (BST is UTC+1 in October) => 8:00 AM UTC
        $this->assertSame('Europe/London', $action->resolveTimezone($contact));
        $this->assertSame('2026-10-15 08:00:00', $sendAt->format('Y-m-d H:i:s'));
    }

    public function test_infers_timezone_from_country_code(): void
    {
        $contact = Contact::create([
            'first_name' => 'Tokyo',
            'last_name' => 'User',
            'email' => 'tokyo@example.jp',
            'properties' => ['country' => 'JP'],
        ]);

        $action = new CalculateRecipientOptimalSendTimeAction;
        $this->assertSame('Asia/Tokyo', $action->resolveTimezone($contact));
    }

    public function test_predictive_sto_uses_historical_peak_open_hour(): void
    {
        $contact = Contact::create([
            'first_name' => 'Engaged',
            'last_name' => 'User',
            'email' => 'engaged@example.com',
            'properties' => ['timezone' => 'America/New_York'],
        ]);

        $campaignPast = Campaign::create([
            'name' => 'Past 1',
            'subject' => 'Old',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
        ]);

        // Create past opened recipients at 14:00 (2 PM) EDT (18:00 UTC)
        CampaignRecipient::create([
            'campaign_id' => $campaignPast->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok1',
            'unsubscribe_token' => 'unsub1',
            'opened_at' => Carbon::parse('2026-09-10 18:15:00', 'UTC'), // 14:15 New York
        ]);

        // A second past campaign: a contact is a recipient of a campaign at most once.
        $campaignPast2 = Campaign::create([
            'name' => 'Past 2',
            'subject' => 'Old',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaignPast2->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok2',
            'unsubscribe_token' => 'unsub2',
            'opened_at' => Carbon::parse('2026-09-15 18:45:00', 'UTC'), // 14:45 New York
        ]);

        $campaignNew = new Campaign([
            'name' => 'STO Campaign',
            'use_sto' => true,
        ]);

        $baseDate = Carbon::parse('2026-10-20 00:00:00', 'UTC');
        $action = new CalculateRecipientOptimalSendTimeAction;
        $optimalSendTime = $action->execute($campaignNew, $contact, $baseDate);

        // 14:00 EDT => 18:00 UTC
        $this->assertSame(18, $optimalSendTime->hour);
    }
}
