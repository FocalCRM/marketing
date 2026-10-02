<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\CompileCampaignMessageAction;
use Focal\Marketing\Actions\DispatchSmsAction;
use Focal\Marketing\Actions\SendCampaignProofAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Mail\CampaignProofMailable;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Contact and company data merged into email HTML must not inject markup.
 */
class MergeTagEscapingTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::create([
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Tom & Jerry',
            'email' => 'mallory@example.com',
        ]);
        $this->contact->associateWith(Company::create(['name' => '<a href="https://evil.test">Win</a>']));
    }

    public function test_campaign_merge_values_are_html_escaped_once(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Newsletter',
            'subject' => 'News',
            'body_html' => '<html><body><p>Hi {{contact.first_name}} {{contact.last_name}} of {{company.name}}</p></body></html>',
        ]);
        $campaign = Campaign::create([
            'name' => 'Launch',
            'subject' => 'Launch',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contact->id,
            'email' => $this->contact->email,
            'status' => RecipientStatus::Pending,
        ]);

        $html = (new CompileCampaignMessageAction)->execute($campaign, $recipient);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('evil.test', $this->linkTargets($html));
        $this->assertStringContainsString('Hi &lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; Jerry of &lt;a href=&quot;https://evil.test&quot;&gt;Win&lt;/a&gt;', $html);
    }

    public function test_workflow_email_merge_values_are_html_escaped(): void
    {
        $html = (new CompileCampaignMessageAction)->compileForContact(
            '<p>Hi {{contact.first_name}} {{contact.last_name}} of {{company.name}}</p>',
            $this->contact,
        );

        $this->assertSame('<p>Hi &lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; Jerry of &lt;a href=&quot;https://evil.test&quot;&gt;Win&lt;/a&gt;</p>', $html);
    }

    private function linkTargets(string $html): string
    {
        preg_match_all('/<a\s[^>]*href="([^"]*)"/i', $html, $matches);

        return implode(' ', $matches[1]);
    }

    public function test_sms_merge_values_are_plain_text(): void
    {
        $this->contact->update(['first_name' => "Sean O'Brien & Co", 'phone' => '+15550100', 'sms_consent' => true]);

        $sms = app(DispatchSmsAction::class)->execute($this->contact, 'Hi {{contact.first_name}}!');

        $this->assertSame("Hi Sean O'Brien & Co!", $sms->message_body);
    }

    public function test_campaign_proof_merge_values_are_html_escaped(): void
    {
        Mail::fake();
        $template = MarketingTemplate::create([
            'name' => 'Newsletter',
            'subject' => 'News',
            'body_html' => '<html><body><p>Hi {{contact.first_name}} of {{company.name}}</p></body></html>',
        ]);
        $campaign = Campaign::create([
            'name' => 'Launch',
            'subject' => 'Launch',
            'sender_name' => 'Focal',
            'sender_email' => 'news@focal.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        app(SendCampaignProofAction::class)->execute($campaign, 'staff@focal.test', $this->contact);

        Mail::assertQueued(CampaignProofMailable::class, fn (CampaignProofMailable $mail): bool => str_contains($mail->htmlBody, '&lt;script&gt;alert(1)&lt;/script&gt;')
            && str_contains($mail->htmlBody, '&lt;a href=&quot;https://evil.test&quot;&gt;Win&lt;/a&gt;')
            && ! str_contains($mail->htmlBody, '<script>'));
    }
}
