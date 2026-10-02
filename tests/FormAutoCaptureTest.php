<?php

declare(strict_types=1);

use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;

test('embed script odden.js is served with form interception code', function () {
    $response = $this->get(route('odden.marketing.track.script'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        ->assertSee('interceptForms')
        ->assertSee('/marketing/forms/auto-capture');
});

test('auto-captures external website form submission creating lead with intent score', function () {
    $payload = [
        'email' => 'inbound.prospect@webflow-site.com',
        'name' => 'Michael Scott',
        'phone' => '+1-555-432-1234',
        'page_url' => 'https://example.com/pricing',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'q4-enterprise-surge',
    ];

    $response = $this->postJson(route('odden.marketing.forms.auto-capture'), $payload);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('is_new', true);

    /** @var Contact $contact */
    $contact = Contact::query()->where('email', 'inbound.prospect@webflow-site.com')->first();
    expect($contact)->not->toBeNull()
        ->and($contact->first_name)->toBe('Michael')
        ->and($contact->last_name)->toBe('Scott')
        ->and($contact->phone)->toBe('+1-555-432-1234')
        ->and($contact->lead_score)->toBe(15)
        ->and($contact->lifecycle_stage)->toBe(LifecycleStage::MarketingQualifiedLead);

    // Activity logged on contact timeline
    expect($contact->activities()->where('title', 'Website Form Auto-Captured')->exists())->toBeTrue();
});
