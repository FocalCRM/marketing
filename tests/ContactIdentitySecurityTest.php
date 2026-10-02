<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\LandingPage;
use Focal\Marketing\Models\MarketingAsset;
use Focal\Marketing\Models\MarketingForm;
use Focal\Marketing\Support\ContactToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Public endpoints must not let a caller choose which contact they act as.
 */
class ContactIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private MarketingForm $form;

    private Contact $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->form = MarketingForm::create([
            'title' => 'Demo Request',
            'slug' => 'demo-request',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => false],
            ],
            'progressive_profiling_enabled' => true,
            'progressive_fields' => [
                ['name' => 'budget', 'label' => 'Annual Budget', 'type' => 'text', 'required' => false],
            ],
        ]);

        $this->victim = Contact::create([
            'first_name' => 'Victoria',
            'email' => 'victoria@example.com',
        ]);
        $this->victim->setProperties(['budget' => '$50k'])->save();
    }

    public function test_hosted_form_does_not_identify_a_contact_by_id_or_email(): void
    {
        $this->get('/forms/demo-request?contact_id='.$this->victim->id)
            ->assertOk()
            ->assertDontSee('Victoria')
            ->assertDontSee('Welcome back');

        $this->get('/forms/demo-request?email=victoria@example.com')
            ->assertOk()
            ->assertDontSee('Victoria')
            ->assertDontSee('Welcome back');
    }

    public function test_hosted_form_identifies_a_contact_from_a_signed_link(): void
    {
        $this->get($this->form->getPublicUrl($this->victim))
            ->assertOk()
            ->assertSee('Welcome back, <strong>Victoria</strong>!', false);
    }

    public function test_hosted_form_rejects_tampered_and_cross_form_tokens(): void
    {
        $other = Contact::create(['first_name' => 'Mallory', 'email' => 'mallory@example.com']);
        [, $signature] = explode('.', ContactToken::make($other, ContactToken::forForm($this->form->id)));

        $this->get('/forms/demo-request?contact='.$this->victim->id.'.'.$signature)
            ->assertOk()
            ->assertDontSee('Victoria');

        $otherForm = MarketingForm::create(['title' => 'Other', 'slug' => 'other', 'fields_schema' => []]);
        $tokenForOtherForm = ContactToken::make($this->victim, ContactToken::forForm($otherForm->id));

        $this->get('/forms/demo-request?contact='.urlencode($tokenForOtherForm))
            ->assertOk()
            ->assertDontSee('Victoria');
    }

    public function test_schema_does_not_reveal_a_contacts_profile_by_id_or_email(): void
    {
        $baseFields = ['first_name', 'email', 'company'];

        $byId = $this->getJson(route('focal.marketing.forms.schema', 'demo-request').'?contact_id='.$this->victim->id);
        $this->assertSame($baseFields, array_column($byId->json('fields'), 'name'));

        $byEmail = $this->getJson(route('focal.marketing.forms.schema', 'demo-request').'?email=victoria@example.com');
        $this->assertSame($baseFields, array_column($byEmail->json('fields'), 'name'));
    }

    public function test_submissions_cannot_attach_to_or_modify_another_contact_by_id(): void
    {
        $payload = [
            'contact_id' => $this->victim->id,
            'first_name' => 'Mallory',
            'email' => 'mallory@example.com',
            'budget' => '$0',
        ];

        $this->post('/forms/demo-request', $payload)->assertOk();
        $this->postJson(route('focal.marketing.forms.api-submit', 'demo-request'), $payload)->assertOk();

        $this->victim->refresh();
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertSame(0, $this->form->submissions()->where('contact_id', $this->victim->id)->count());

        $mallory = Contact::query()->where('email', 'mallory@example.com')->sole();
        $this->assertSame(2, $this->form->submissions()->where('contact_id', $mallory->id)->count());
        $this->assertArrayNotHasKey('contact_id', $this->form->submissions()->first()->form_data);
    }

    public function test_landing_page_submissions_cannot_attach_to_another_contact_by_id(): void
    {
        LandingPage::create([
            'title' => 'Launch',
            'slug' => 'launch',
            'headline' => 'Launch',
            'form_id' => $this->form->id,
            'is_published' => true,
        ]);

        $this->post('/p/launch/submit', [
            'contact_id' => $this->victim->id,
            'first_name' => 'Mallory',
            'email' => 'mallory@example.com',
            'budget' => '$0',
        ])->assertRedirect();

        $this->victim->refresh();
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertSame(0, $this->form->submissions()->where('contact_id', $this->victim->id)->count());
    }

    public function test_signed_submission_updates_the_verified_contact(): void
    {
        $this->post('/forms/demo-request', [
            'contact' => ContactToken::make($this->victim, ContactToken::forForm($this->form->id)),
            'budget' => '$250k',
        ])->assertOk();

        $this->victim->refresh();
        $this->assertSame('$250k', $this->victim->getProperty('budget'));
        $this->assertSame(1, $this->form->submissions()->where('contact_id', $this->victim->id)->count());
    }

    public function test_asset_downloads_are_attributed_only_with_a_valid_signature(): void
    {
        $asset = MarketingAsset::create([
            'name' => 'Pricing Report',
            'slug' => 'pricing-report',
            'external_url' => 'https://example.com/report.pdf',
        ]);

        $this->get(route('focal.marketing.assets.download', ['slug' => 'pricing-report', 'contact_id' => $this->victim->id]))
            ->assertRedirect('https://example.com/report.pdf');
        $this->get(route('focal.marketing.assets.download', ['slug' => 'pricing-report', 'contact_id' => $this->victim->id, 'signature' => str_repeat('0', 64)]))
            ->assertRedirect('https://example.com/report.pdf');

        $this->assertSame(0, $asset->fresh()->unique_leads_count);

        $this->get($asset->getDownloadUrl($this->victim))->assertRedirect('https://example.com/report.pdf');

        $this->assertSame(1, $asset->fresh()->unique_leads_count);
    }
}
