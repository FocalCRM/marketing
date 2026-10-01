<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use App\Models\User;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\CalculateCompanyIntentScoreAction;
use Focal\Marketing\Actions\CheckFatiguePolicyAction;
use Focal\Marketing\Actions\DetectUnengagedContactsAction;
use Focal\Marketing\Actions\ExecuteSunsetPolicyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbmAndSunsetPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_abm_company_intent_scoring_and_surge_detection(): void
    {
        $company = Company::create([
            'name' => 'Stripe Inc',
            'domain' => 'stripe.com',
            'account_tier' => 'tier_1', // Strategic Enterprise (+50 bonus)
        ]);

        // Create 2 contacts from Stripe
        $cto = Contact::create([
            'first_name' => 'Patrick',
            'last_name' => 'Collison',
            'email' => 'patrick@stripe.com',
            'lead_score' => 60,
            'last_contacted_at' => now()->subDays(2),
        ]);

        $director = Contact::create([
            'first_name' => 'Claire',
            'last_name' => 'Hughes',
            'email' => 'claire@stripe.com',
            'lead_score' => 40,
            'last_contacted_at' => now()->subDays(5),
        ]);

        $cto->associateWith($company, 'primary');
        $director->associateWith($company, 'primary');

        $action = new CalculateCompanyIntentScoreAction;
        $updated = $action->execute($company);

        // Score: 60 (CTO) + 40 (Director) + 50 (Tier 1 bonus) = 150 pts
        $this->assertSame(150, $updated->intent_score);
        $this->assertSame(2, $updated->buying_committee_size);
        $this->assertTrue($updated->intent_surge);
        $this->assertTrue($updated->isTargetAccount());
        $this->assertTrue($updated->isSurging());

        // Verify automated task logged for account owner
        $this->assertDatabaseHas('focal_activities', [
            'subject_id' => $company->id,
            'title' => 'ABM Intent Surge: Stripe Inc',
        ]);
    }

    public function test_sunset_policy_identifies_unengaged_contacts_and_suppresses_them(): void
    {
        // 1. Contact active recently (last sent 10 days ago)
        $activeContact = Contact::create([
            'first_name' => 'Active',
            'last_name' => 'User',
            'email' => 'active@example.com',
            'last_marketing_email_sent_at' => now()->subDays(10),
            'is_unengaged' => false,
        ]);

        // 2. Dormant contact (last sent 120 days ago)
        $dormantContact = Contact::create([
            'first_name' => 'Dormant',
            'last_name' => 'Lead',
            'email' => 'dormant@example.com',
            'last_marketing_email_sent_at' => now()->subDays(120),
            'is_unengaged' => false,
        ]);

        $detector = new DetectUnengagedContactsAction;
        $unengaged = $detector->execute(daysInactive: 90);

        $this->assertCount(1, $unengaged);
        $this->assertSame($dormantContact->id, $unengaged->first()?->id);

        $dormantContact->refresh();
        $this->assertTrue($dormantContact->is_unengaged);
        $this->assertSame('flagged', $dormantContact->sunset_stage);

        $activeContact->refresh();
        $this->assertFalse($activeContact->is_unengaged);

        // 3. Execute sunset progression: flagged -> reengagement_sent
        $executor = new ExecuteSunsetPolicyAction;
        $executor->execute($dormantContact);

        $dormantContact->refresh();
        $this->assertSame('reengagement_sent', $dormantContact->sunset_stage);
        $this->assertDatabaseHas('focal_activities', [
            'subject_id' => $dormantContact->id,
            'title' => 'Sunset Policy: Re-engagement Step Triggered',
        ]);

        // 4. Execute final suppression: reengagement_sent -> suppressed
        $executor->execute($dormantContact);

        $dormantContact->refresh();
        $this->assertSame('suppressed', $dormantContact->sunset_stage);
        $this->assertDatabaseHas('focal_activities', [
            'subject_id' => $dormantContact->id,
            'title' => 'Sunset Policy: Contact Suppressed',
        ]);

        // 5. Verify CheckFatiguePolicyAction blocks suppressed contact from campaign broadcasts
        $fatigueChecker = new CheckFatiguePolicyAction;
        $fatigueResult = $fatigueChecker->execute($dormantContact);

        $this->assertFalse($fatigueResult['can_send']);
        $this->assertSame('Contact suppressed under deliverability sunset policy', $fatigueResult['reason']);
    }

    public function test_filament_company_resource_displays_abm_tier(): void
    {
        $user = User::factory()->create();

        $company = Company::create([
            'name' => 'Datadog Europe',
            'domain' => 'datadog.com',
            'account_tier' => 'tier_1',
            'intent_score' => 85,
            'intent_surge' => true,
        ]);

        $response = $this->actingAs($user)->get('/admin/companies');
        $response->assertStatus(200);
        $response->assertSee('Datadog Europe');
        $response->assertSee('Tier 1');
    }
}
