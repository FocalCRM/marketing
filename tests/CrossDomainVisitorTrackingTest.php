<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\RecordWebVisitAction;
use Odden\Marketing\Actions\StitchVisitorToContactAction;
use Odden\Marketing\Models\LandingPage;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;

const CROSS_DOMAIN_VID = '3f9a1c0e8b7d4a6f9e2c1b0a5d4e3f21';

/**
 * Post a raw body the way a browser's sendBeacon()/fetch() does when given a string or a text/plain Blob.
 */
function postRawBody(mixed $test, string $uri, array $payload, string $contentType = 'text/plain;charset=UTF-8'): TestResponse
{
    return $test->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => '*/*',
    ], json_encode($payload));
}

test('auto-capture accepts a JSON body sent as text/plain by sendBeacon', function () {
    VisitorSession::create(['visitor_token' => CROSS_DOMAIN_VID]);

    postRawBody($this, route('odden.marketing.forms.auto-capture'), [
        'email' => 'beacon@example.com',
        'name' => 'Bea Con',
        'page_url' => 'https://www.example.com/contact',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('is_new', true);

    $contact = Contact::query()->where('email', 'beacon@example.com')->firstOrFail();
    expect($contact->first_name)->toBe('Bea')
        ->and(VisitorSession::query()->where('visitor_token', CROSS_DOMAIN_VID)->value('contact_id'))->toBe($contact->id);
});

test('auto-capture still rejects a text/plain body that is not a JSON object', function () {
    $this->call('POST', route('odden.marketing.forms.auto-capture'), [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], 'email=someone@example.com')->assertStatus(422);
});

test('pageview accepts a JSON body sent as text/plain', function () {
    postRawBody($this, route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/features',
        'path' => '/features',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk()->assertJsonPath('visitor_token', CROSS_DOMAIN_VID);

    expect(PageView::query()->value('path'))->toBe('/features');
});

test('odden.js sends a client-generated visitor id as CORS-safelisted text/plain requests', function () {
    $script = (string) $this->get(route('odden.marketing.track.script'))->assertOk()->getContent();

    expect($script)
        ->toContain('oddenVisitorId')
        ->toContain('_odden_vid')
        ->toContain('localStorage')
        ->toContain("new Blob([body], { type: 'text/plain;charset=UTF-8' })")
        ->not->toContain("getCookie('odden_vid')")
        ->not->toContain('navigator.sendBeacon(ODDEN_AUTO_CAPTURE_URL, JSON.stringify(payload))');
});

test('embed.js reads the same visitor id as odden.js', function () {
    $embed = (string) $this->get(route('odden.marketing.forms.embed-script'))->assertOk()->getContent();
    $tracker = (string) $this->get(route('odden.marketing.track.script'))->assertOk()->getContent();

    expect($embed)->toContain('oddenVisitorId')
        ->toContain("payload['visitor_token'] = oddenVisitorId()")
        ->not->toContain('_odden_visitor_token');

    // Both scripts embed the identical helper, so they always agree on the storage key and format.
    preg_match('/function oddenVisitorId\(\) \{.*?\n    \}\n/s', $embed, $embedHelper);
    preg_match('/function oddenVisitorId\(\) \{.*?\n    \}\n/s', $tracker, $trackerHelper);
    expect($embedHelper[0] ?? null)->not->toBeNull()->toBe($trackerHelper[0] ?? 'missing');
});

test('pageviews with an explicit visitor token create and then reuse one session without cookies', function () {
    $first = $this->postJson(route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk()->assertJsonPath('visitor_token', CROSS_DOMAIN_VID);

    // A second request with no cookies (a cross-domain fetch without credentials) carrying the same id.
    $this->postJson(route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/pricing',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk()->assertJsonPath('session_id', $first->json('session_id'));

    expect(VisitorSession::query()->count())->toBe(1)
        ->and(PageView::query()->where('session_id', $first->json('session_id'))->count())->toBe(2);
});

test('an explicit visitor token takes precedence over the odden_vid cookie', function () {
    $this->withCookie('odden_vid', 'legacyServerIssuedToken0123456789abcdef')
        ->postJson(route('odden.marketing.track.pageview'), [
            'url' => 'https://www.example.com/',
            'visitor_token' => CROSS_DOMAIN_VID,
        ])->assertOk()
        ->assertJsonPath('visitor_token', CROSS_DOMAIN_VID)
        ->assertCookie('odden_vid', CROSS_DOMAIN_VID);
});

test('malformed visitor tokens are ignored and replaced with a fresh one', function (mixed $token) {
    $response = $this->postJson(route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/',
        'visitor_token' => $token,
    ])->assertOk();

    $issued = (string) $response->json('visitor_token');
    expect($issued)->not->toBe($token)->toMatch('/^[A-Za-z0-9_-]{16,64}$/')
        ->and(VisitorSession::query()->where('visitor_token', $issued)->exists())->toBeTrue();
})->with([
    'too short' => ['abc'],
    'bad characters' => ['<script>alert(1)</script>-padding-pad'],
    'too long' => [str_repeat('a', 65)],
    'array' => [['nested' => 'value']],
]);

test('embedded form submission with the visitor token stitches earlier sessions to the created contact', function () {
    $this->postJson(route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/blog/post',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk();

    $form = MarketingForm::create([
        'title' => 'Newsletter',
        'slug' => 'newsletter',
        'fields_schema' => [['name' => 'email', 'type' => 'email', 'required' => true]],
        'is_active' => true,
    ]);

    // embed.js posts JSON to the API endpoint with the visitor id from odden.js.
    $this->postJson(route('odden.marketing.forms.api-submit', $form->slug), [
        'email' => 'reader@example.com',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk();

    $contact = Contact::query()->where('email', 'reader@example.com')->firstOrFail();
    $session = VisitorSession::query()->where('visitor_token', CROSS_DOMAIN_VID)->firstOrFail();

    expect($session->contact_id)->toBe($contact->id)
        ->and(PageView::query()->where('session_id', $session->id)->pluck('contact_id')->all())->toBe([$contact->id]);
});

test('hosted landing pages still stitch through the odden_vid cookie', function () {
    $token = 'legacyServerIssuedToken0123456789abcdef';

    $form = MarketingForm::create([
        'title' => 'Early Access',
        'slug' => 'early-access',
        'fields_schema' => [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
    ]);
    LandingPage::create([
        'title' => 'Early Access',
        'slug' => 'early-access',
        'form_id' => $form->id,
        'is_published' => true,
    ]);

    $this->withCookie('odden_vid', $token)->get('/p/early-access')->assertOk();

    $this->withCookie('odden_vid', $token)
        ->post('/p/early-access/submit', ['email' => 'lander@example.com'])
        ->assertRedirect();

    $contact = Contact::query()->where('email', 'lander@example.com')->firstOrFail();
    expect(VisitorSession::query()->where('visitor_token', $token)->value('contact_id'))->toBe($contact->id);
});

test('stitching ignores malformed tokens and never moves sessions owned by another contact', function () {
    $owner = Contact::create(['email' => 'owner@example.com']);
    $other = Contact::create(['email' => 'other@example.com']);

    app(RecordWebVisitAction::class)->execute([
        'visitor_token' => CROSS_DOMAIN_VID,
        'contact_id' => $owner->id,
        'url' => 'https://www.example.com/',
    ]);

    expect(app(StitchVisitorToContactAction::class)->execute(CROSS_DOMAIN_VID, $other))->toBe(0)
        ->and(app(StitchVisitorToContactAction::class)->execute('bad', $other))->toBe(0)
        ->and(VisitorSession::query()->where('visitor_token', CROSS_DOMAIN_VID)->value('contact_id'))->toBe($owner->id);
});

test('form submissions with a non-string visitor token are accepted and skip stitching', function (string $route) {
    $this->postJson(route('odden.marketing.track.pageview'), [
        'url' => 'https://www.example.com/',
        'visitor_token' => CROSS_DOMAIN_VID,
    ])->assertOk();

    $form = MarketingForm::create([
        'title' => 'Newsletter',
        'slug' => 'newsletter',
        'fields_schema' => [['name' => 'email', 'type' => 'email', 'required' => true]],
        'is_active' => true,
    ]);

    $this->postJson(route($route, $form->slug), [
        'email' => 'array-token@example.com',
        'visitor_token' => ['nested' => CROSS_DOMAIN_VID],
    ])->assertSuccessful();

    expect(Contact::query()->where('email', 'array-token@example.com')->exists())->toBeTrue()
        ->and(VisitorSession::query()->where('visitor_token', CROSS_DOMAIN_VID)->value('contact_id'))->toBeNull();
})->with([
    'api endpoint' => ['odden.marketing.forms.api-submit'],
    'hosted endpoint' => ['odden.marketing.forms.submit'],
]);

test('landing page submissions with a non-string visitor token are accepted', function () {
    $form = MarketingForm::create([
        'title' => 'Early Access',
        'slug' => 'early-access',
        'fields_schema' => [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
    ]);
    LandingPage::create([
        'title' => 'Early Access',
        'slug' => 'early-access',
        'form_id' => $form->id,
        'is_published' => true,
    ]);

    $this->post('/p/early-access/submit', ['email' => 'lander-array@example.com', 'visitor_token' => ['x', 'y']])
        ->assertRedirect();

    expect(Contact::query()->where('email', 'lander-array@example.com')->exists())->toBeTrue();
});
