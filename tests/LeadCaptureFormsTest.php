<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\MarketingForm;

class LeadCaptureFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_render_hosted_lead_capture_form(): void
    {
        $form = MarketingForm::create([
            'title' => 'Contact Enterprise Sales',
            'slug' => 'contact-sales',
            'description' => 'Speak with an enterprise specialist today.',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Business Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => false],
            ],
            'submit_button_text' => 'Request Demo',
        ]);

        $response = $this->get('/forms/contact-sales');

        $response->assertSuccessful();
        $response->assertSee('Contact Enterprise Sales');
        $response->assertSee('Speak with an enterprise specialist today.');
        $response->assertSee('Request Demo');
    }

    public function test_can_submit_lead_capture_form_and_provision_contact_and_company(): void
    {
        $form = MarketingForm::create([
            'title' => 'Contact Enterprise Sales',
            'slug' => 'contact-sales',
            'fields_schema' => [
                ['name' => 'first_name', 'type' => 'text', 'required' => true],
                ['name' => 'last_name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'type' => 'text', 'required' => false],
            ],
        ]);

        $response = $this->post('/forms/contact-sales', [
            'first_name' => 'Gordon',
            'last_name' => 'Freeman',
            'email' => 'gordon@blackmesa.gov',
            'company' => 'Black Mesa Research Facility',
        ]);

        $response->assertSuccessful();
        $response->assertSee('Thank You!');

        $form->refresh();
        $this->assertSame(1, $form->submissions_count);

        /** @var Contact|null $contact */
        $contact = Contact::query()->where('email', 'gordon@blackmesa.gov')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Gordon', $contact->first_name);
        $this->assertSame('Freeman', $contact->last_name);
        $this->assertSame(LeadStatus::New, $contact->lead_status);
        $this->assertSame(LifecycleStage::Lead, $contact->lifecycle_stage);

        /** @var Company|null $company */
        $company = Company::query()->where('name', 'Black Mesa Research Facility')->first();
        $this->assertNotNull($company);
        $this->assertTrue($contact->isAssociatedWith($company));

        $this->assertCount(1, $form->submissions);
        $this->assertSame($contact->id, $form->submissions->first()?->contact_id);
    }

    public function test_can_submit_via_api_without_csrf_and_receive_json(): void
    {
        $form = MarketingForm::create([
            'title' => 'Newsletter Signup',
            'slug' => 'newsletter',
            'fields_schema' => [
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
            'success_message' => 'Subscribed successfully!',
        ]);

        $response = $this->postJson('/api/marketing/forms/newsletter', [
            'email' => 'alyx@city17.org',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'success' => true,
            'message' => 'Subscribed successfully!',
        ]);

        $this->assertDatabaseHas('odden_contacts', [
            'email' => 'alyx@city17.org',
        ]);
    }
}
