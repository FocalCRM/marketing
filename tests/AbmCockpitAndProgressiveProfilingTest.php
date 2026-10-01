<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Filament\Pages\AbmCockpit;
use Focal\Filament\Resources\CampaignResource;
use Focal\Marketing\Actions\CalculateCompanyIntentScoreAction;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\MarketingForm;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbmCockpitAndProgressiveProfilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_abm_cockpit_page_metrics_and_actions(): void
    {
        $tier1 = Company::create([
            'name' => 'Snowflake Computing',
            'domain' => 'snowflake.com',
            'account_tier' => 'tier_1',
            'intent_score' => 90,
            'intent_surge' => true,
            'buying_committee_size' => 3,
        ]);

        $tier2 = Company::create([
            'name' => 'Datadog Inc',
            'domain' => 'datadoghq.com',
            'account_tier' => 'tier_2',
            'intent_score' => 40,
            'intent_surge' => false,
            'buying_committee_size' => 1,
        ]);

        Company::create([
            'name' => 'Small Startup',
            'domain' => 'startup.test',
            'account_tier' => 'tier_3',
            'intent_score' => 10,
            'intent_surge' => false,
        ]);

        $cockpit = new AbmCockpit;

        $this->assertSame(2, $cockpit->totalTargetAccounts);
        $this->assertSame(1, $cockpit->surgingAccountsCount);
        $this->assertSame(1, $cockpit->tier1AccountsCount);
        $this->assertSame(1, $cockpit->tier2AccountsCount);
        $this->assertSame(65.0, $cockpit->averageIntentScore);
        $this->assertSame(4, $cockpit->totalBuyingCommittee);

        // Test accounts filtering by tier
        $cockpit->setTier('surging');
        $this->assertCount(1, $cockpit->accounts);
        $this->assertSame('Snowflake Computing', $cockpit->accounts->first()?->name);

        $cockpit->setTier('all');
        $this->assertCount(2, $cockpit->accounts);

        // Test recalculateCompany action
        $action = new CalculateCompanyIntentScoreAction;
        $cockpit->recalculateCompany($tier1->id, $action);
        $this->assertNotNull($tier1->fresh()?->last_intent_activity_at);

        // Test recalculateAll
        $cockpit->recalculateAll($action);
        $this->assertDatabaseHas('focal_companies', [
            'id' => $tier1->id,
            'account_tier' => 'tier_1',
        ]);
    }

    public function test_progressive_profiling_and_smart_forms(): void
    {
        /** @var MarketingForm $form */
        $form = MarketingForm::create([
            'title' => 'Enterprise Demo Request',
            'slug' => 'enterprise-demo',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => false],
            ],
            'progressive_profiling_enabled' => true,
            'progressive_fields' => [
                ['name' => 'budget', 'label' => 'Annual Budget', 'type' => 'text', 'required' => true],
                ['name' => 'timeline', 'label' => 'Purchase Timeline', 'type' => 'select', 'options' => ['Immediate', '3-6 months'], 'required' => false],
                ['name' => 'crm_replaced', 'label' => 'Current CRM', 'type' => 'text', 'required' => false],
            ],
        ]);

        // 1. Unknown visitor: resolves base fields
        $fieldsForNewVisitor = $form->resolveFieldsForContact(null);
        $this->assertCount(3, $fieldsForNewVisitor);
        $this->assertSame('first_name', $fieldsForNewVisitor[0]['name']);
        $this->assertSame('email', $fieldsForNewVisitor[1]['name']);

        // 2. Known returning contact with first_name and email already captured
        $contact = Contact::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@skynet.test',
        ]);

        $resolvedFields = $form->resolveFieldsForContact($contact);
        // Known fields (first_name, email) are swapped with progressive questions (budget, timeline)
        $this->assertCount(3, $resolvedFields);
        $this->assertSame('budget', $resolvedFields[0]['name']);
        $this->assertTrue($resolvedFields[0]['is_progressive']);
        $this->assertSame('timeline', $resolvedFields[1]['name']);
        $this->assertTrue($resolvedFields[1]['is_progressive']);
        $this->assertSame('company', $resolvedFields[2]['name']);

        // 3. Render GET /forms/{slug}?contact_id={id}
        $response = $this->get('/forms/enterprise-demo?contact_id='.$contact->id);
        $response->assertOk();
        $response->assertSee('Welcome back, <strong>Sarah</strong>!', false);
        $response->assertSee('Annual Budget');
        $response->assertSee('Purchase Timeline');
        $response->assertSee('Smart Question');

        // 4. Submit progressive form data
        $postResponse = $this->post('/forms/enterprise-demo', [
            'contact_id' => $contact->id,
            'budget' => '$100,000+',
            'timeline' => 'Immediate',
            'crm_replaced' => 'Salesforce',
        ]);

        $postResponse->assertOk();

        // Verify contact's profile was progressively enriched
        $contact->refresh();
        $this->assertSame('$100,000+', $contact->getProperty('budget'));
        $this->assertSame('Immediate', $contact->getProperty('timeline'));
        $this->assertSame('Salesforce', $contact->getProperty('crm_replaced'));

        // Verify form submission recorded
        $this->assertDatabaseHas('focal_marketing_form_submissions', [
            'form_id' => $form->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_campaign_and_template_device_mode_preview(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Product Announcement',
            'subject' => 'Major Update: Focal 2.0 Released',
            'preview_text' => 'Discover our new Account-Based Marketing cockpit.',
            'body_html' => '<h1>Hello {{contact.first_name}}</h1><p>We are thrilled to unveil Focal 2.0 for {{company.name}}.</p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Q4 Product Launch Broadcast',
            'subject' => 'Major Update: Focal 2.0 Released',
            'preview_text' => 'Discover our new Account-Based Marketing cockpit.',
            'sender_name' => 'Focal Marketing',
            'sender_email' => 'marketing@focal.test',
            'template_id' => $template->id,
        ]);

        // Test sample rendering helper
        $html = CampaignResource::renderSampleHtml($campaign);
        $this->assertStringContainsString('Hello Alex', $html);
        $this->assertStringContainsString('Acme Corporation', $html);

        // Test rendering the preview view
        $view = view('focal-marketing::template-preview', [
            'renderedHtml' => $html,
            'template' => $campaign,
        ])->render();

        $this->assertStringContainsString('Desktop (600px)', $view);
        $this->assertStringContainsString('Mobile Device (375px)', $view);
        $this->assertStringContainsString('Major Update: Focal 2.0 Released', $view);
        $this->assertStringContainsString('Alex Morgan', $view);
    }
}
