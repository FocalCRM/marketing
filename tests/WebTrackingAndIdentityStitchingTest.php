<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\RecordWebVisitAction;
use Odden\Marketing\Actions\StitchVisitorToContactAction;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;

class WebTrackingAndIdentityStitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pageview_endpoint_creates_session_and_page_view_records(): void
    {
        $response = $this->postJson('/marketing/track/pageview', [
            'url' => 'https://odden.test/features/crm',
            'path' => '/features/crm',
            'title' => 'CRM Features | Odden',
            'referer' => 'https://google.com',
            'utm_source' => 'google',
            'utm_medium' => 'organic',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'session_id', 'visitor_token']);
        $response->assertCookie('odden_vid');

        $session = VisitorSession::query()->first();
        $this->assertNotNull($session);
        $this->assertSame('google', $session->utm_source);
        $this->assertSame('organic', $session->utm_medium);

        $pageView = PageView::query()->first();
        $this->assertNotNull($pageView);
        $this->assertSame('/features/crm', $pageView->path);
        $this->assertSame($session->id, $pageView->session_id);
    }

    public function test_visiting_high_intent_pricing_page_increases_lead_score_for_known_contact(): void
    {
        $contact = Contact::create([
            'first_name' => 'Barbara',
            'last_name' => 'Liskov',
            'email' => 'barbara@mit.edu',
            'lead_score' => 10,
        ]);

        $action = new RecordWebVisitAction;
        $action->execute([
            'contact_id' => $contact->id,
            'url' => 'https://odden.test/pricing',
            'path' => '/pricing',
            'title' => 'Odden Pricing & Plans',
        ]);

        $contact->refresh();
        // High-intent path '/pricing' awards +20 points -> 10 + 20 = 30
        $this->assertSame(30, $contact->lead_score);
        $this->assertDatabaseHas('odden_marketing_lead_score_logs', [
            'contact_id' => $contact->id,
            'event_description' => 'High Intent Web Visit: /pricing (+20 pts)',
        ]);
    }

    public function test_odden_tracking_javascript_script_is_served(): void
    {
        $response = $this->get('/marketing/odden.js');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
        $this->assertStringContainsString('/marketing/track/pageview', $response->getContent() ?: '');
        $this->assertStringContainsString('odden_vid', $response->getContent() ?: '');
    }

    public function test_retroactive_identity_stitching_links_prior_browsing_history_to_contact(): void
    {
        $visitorToken = 'anon_session_token_xyz_123';

        // 1. Record anonymous browsing sessions
        $visitAction = new RecordWebVisitAction;
        $visitAction->execute([
            'visitor_token' => $visitorToken,
            'url' => 'https://odden.test/blog/modern-sales',
            'path' => '/blog/modern-sales',
            'title' => 'Modern Sales Blog',
        ]);
        $visitAction->execute([
            'visitor_token' => $visitorToken,
            'url' => 'https://odden.test/features',
            'path' => '/features',
            'title' => 'Features Overview',
        ]);

        $session = VisitorSession::query()->where('visitor_token', $visitorToken)->first();
        $this->assertNotNull($session);
        $this->assertNull($session->contact_id);
        $this->assertSame(2, PageView::query()->where('session_id', $session->id)->count());

        // 2. Contact identifies via form submission with visitor_token
        $contact = Contact::create([
            'first_name' => 'Donald',
            'last_name' => 'Knuth',
            'email' => 'knuth@stanford.edu',
        ]);

        $stitchAction = new StitchVisitorToContactAction;
        $stitched = $stitchAction->execute($visitorToken, $contact);

        $this->assertSame(1, $stitched);

        $session->refresh();
        $this->assertSame($contact->id, $session->contact_id);

        $pageViews = PageView::query()->where('session_id', $session->id)->get();
        $this->assertTrue($pageViews->every(fn (PageView $pv) => $pv->contact_id === $contact->id));
    }
}
