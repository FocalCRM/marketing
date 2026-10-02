<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;

class MarketingFormEmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_endpoint_returns_json_fields_and_action_url(): void
    {
        $form = MarketingForm::create([
            'title' => 'Webinar Signup Form',
            'slug' => 'webinar-signup',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => false],
            ],
            'submit_button_text' => 'Reserve Seat',
            'is_active' => true,
        ]);

        $response = $this->getJson("/marketing/forms/{$form->slug}/schema.json");

        $response->assertOk();
        $response->assertJson([
            'title' => 'Webinar Signup Form',
            'slug' => 'webinar-signup',
            'submit_button_text' => 'Reserve Seat',
            'fields' => [
                ['name' => 'first_name', 'label' => 'First Name', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'required' => true],
            ],
        ]);
        $response->assertJsonPath('action_url', url("/api/marketing/forms/{$form->slug}"));
    }

    public function test_embed_script_serves_valid_javascript(): void
    {
        $response = $this->get('/marketing/forms/embed.js');

        $response->assertOk();
        $this->assertStringContainsString('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('odden-embedded-form', $response->getContent());
        $this->assertStringContainsString('renderForm', $response->getContent());
        $this->assertStringContainsString('odden-modal-overlay', $response->getContent());
        $this->assertStringContainsString('odden-slide-in', $response->getContent());
        $this->assertStringContainsString('exit-intent', $response->getContent());
    }

    public function test_submitting_embedded_form_via_api_endpoint(): void
    {
        $form = MarketingForm::create([
            'title' => 'Contact Us',
            'slug' => 'contact-us',
            'fields_schema' => [
                ['name' => 'email', 'type' => 'email', 'required' => true],
                ['name' => 'message', 'type' => 'textarea', 'required' => false],
            ],
            'success_message' => 'Thanks for reaching out!',
            'is_active' => true,
        ]);

        $payload = [
            'email' => 'prospect@acme.com',
            'message' => 'We want to buy Odden Enterprise.',
            'visitor_token' => 'vid_test_123',
        ];

        $response = $this->postJson("/api/marketing/forms/{$form->slug}", $payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Thanks for reaching out!',
        ]);

        $submission = FormSubmission::query()->where('form_id', $form->id)->first();
        $this->assertNotNull($submission);
        $this->assertSame('prospect@acme.com', $submission->form_data['email']);
    }
}
