<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Odden\Core\Models\Contact;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Models\NpsSurvey;
use Odden\Marketing\Support\ContactToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * In-email AMP endpoints answer only AMP email clients, and RSVPs act only for the recipient the link was issued to.
 */
class AmpFormSecurityTest extends TestCase
{
    use RefreshDatabase;

    private MarketingEvent $event;

    private Contact $invitee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = MarketingEvent::create([
            'title' => 'Keynote',
            'slug' => 'keynote',
            'starts_at' => now()->addWeek(),
        ]);

        $this->invitee = Contact::create(['first_name' => 'Ina', 'email' => 'ina@example.com']);
    }

    public function test_feedback_rejects_disallowed_or_missing_origins_without_cors_headers(): void
    {
        $nps = NpsResponse::create([
            'survey_id' => NpsSurvey::create(['name' => 'CSAT', 'is_active' => true])->id,
            'score' => 0,
            'category' => 'passive',
            'token' => 'nps_token_amp_123',
        ]);

        foreach ([['Origin' => 'https://evil.test'], []] as $headers) {
            $this->withHeaders($headers)
                ->postJson(route('odden.marketing.amp.feedback'), ['token' => 'nps_token_amp_123', 'score' => 1])
                ->assertForbidden()
                ->assertHeaderMissing('Access-Control-Allow-Origin')
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
        }

        $this->assertSame(0, $nps->fresh()->score);
    }

    public function test_feedback_follows_the_amp_email_cors_spec_for_allowed_origins(): void
    {
        $this->withHeaders(['Origin' => 'https://mail.google.com', 'AMP-Email-Sender' => 'news@odden.test'])
            ->postJson(route('odden.marketing.amp.feedback'), ['score' => 9])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://mail.google.com')
            ->assertHeader('AMP-Email-Allow-Sender', 'news@odden.test')
            ->assertHeader('Access-Control-Expose-Headers', 'AMP-Email-Allow-Sender')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_allowed_origins_are_configurable(): void
    {
        config(['odden-marketing.amp.allowed_origins' => ['https://mail.example.test']]);

        $this->withHeaders(['Origin' => 'https://mail.google.com'])
            ->postJson(route('odden.marketing.amp.feedback'), ['score' => 9])
            ->assertForbidden();

        $this->withHeaders(['Origin' => 'https://mail.example.test'])
            ->postJson(route('odden.marketing.amp.feedback'), ['score' => 9])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://mail.example.test');
    }

    public function test_rsvp_never_creates_or_selects_contacts_by_submitted_email(): void
    {
        $this->withHeaders(['Origin' => 'https://mail.google.com'])
            ->postJson(route('odden.marketing.amp.rsvp'), [
                'event_slug' => 'keynote',
                'email' => 'stranger@example.com',
                'status' => 'attending',
            ])
            ->assertStatus(422);

        $this->withHeaders(['Origin' => 'https://mail.google.com'])
            ->postJson(route('odden.marketing.amp.rsvp'), [
                'event_slug' => 'keynote',
                'email' => 'ina@example.com',
                'status' => 'declined',
            ])
            ->assertStatus(422);

        $this->assertNull(Contact::query()->where('email', 'stranger@example.com')->first());
        $this->assertSame(0, MarketingEventRegistration::query()->count());
    }

    public function test_rsvp_rejects_forged_and_cross_event_tokens(): void
    {
        $otherEvent = MarketingEvent::create(['title' => 'Other', 'slug' => 'other', 'starts_at' => now()->addWeek()]);

        foreach ([
            $this->invitee->id.'.'.str_repeat('a', 64),
            $otherEvent->rsvpTokenFor($this->invitee),
            ContactToken::make($this->invitee, ContactToken::forForm($this->event->id)),
        ] as $token) {
            $this->withHeaders(['Origin' => 'https://mail.google.com'])
                ->postJson(route('odden.marketing.amp.rsvp'), ['event_slug' => 'keynote', 'token' => $token])
                ->assertForbidden()
                ->assertHeader('Access-Control-Allow-Origin', 'https://mail.google.com');
        }

        $this->assertSame(0, MarketingEventRegistration::query()->count());
    }

    public function test_rsvp_registers_the_contact_the_signed_token_was_issued_for(): void
    {
        $this->withHeaders(['Origin' => 'https://mail.google.com'])
            ->postJson(route('odden.marketing.amp.rsvp'), [
                'event_slug' => 'keynote',
                'token' => $this->event->rsvpTokenFor($this->invitee),
                'email' => 'someone-else@example.com',
                'status' => 'tentative',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success', 'rsvp_status' => 'tentative']);

        $registration = MarketingEventRegistration::query()->sole();
        $this->assertSame($this->invitee->id, $registration->contact_id);
        $this->assertSame('tentative', $registration->status);
        $this->assertNull(Contact::query()->where('email', 'someone-else@example.com')->first());
    }
}
