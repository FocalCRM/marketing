<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Marketing\Actions\LintCampaignDeliverabilityAction;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingTemplate;

class CampaignDeliverabilityLinterTest extends TestCase
{
    use RefreshDatabase;

    public function test_linter_flags_freemail_sender_and_missing_unsubscribe_link(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Uncompliant Promo',
            'subject' => 'BUY NOW 100% FREE $$$',
            'body_html' => '<p>Click here to claim your reward: <a href="#">Click</a></p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'High Risk Promo',
            'subject' => 'BUY NOW 100% FREE $$$',
            'sender_name' => 'Spammy Sender',
            'sender_email' => 'sales@gmail.com', // Public freemail domain triggers DMARC warning
            'template_id' => $template->id,
            'preview_text' => null, // Missing preview text
        ]);

        $action = new LintCampaignDeliverabilityAction;
        $result = $action->execute($campaign);

        $this->assertLessThan(60, $result['score']);
        $this->assertSame('critical', $result['status']);

        $rules = array_column($result['warnings'], 'rule');
        $this->assertContains('sender_domain_authenticated', $rules);
        $this->assertContains('unsubscribe_compliance', $rules);
        $this->assertContains('subject_spam_words', $rules);
        $this->assertContains('preview_text_provided', $rules);
        $this->assertContains('broken_placeholder_links', $rules);

        $this->assertNotEmpty($result['recommendations']);
    }

    public function test_linter_passes_clean_authenticated_campaign(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Corporate Product Update',
            'subject' => 'Introducing New Security Features',
            'body_html' => '<div><p>We are pleased to introduce enhanced encryption and audit capabilities across your workspace.</p><p><a href="https://odden.test/security">Learn more</a></p><footer><p><a href="{{unsubscribe_url}}">Unsubscribe from updates</a></p></footer></div>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Security Release Announcement',
            'subject' => 'Introducing New Security Features',
            'preview_text' => 'Enhanced encryption and enterprise audit logging are now live.',
            'sender_name' => 'Odden Security Team',
            'sender_email' => 'security@odden.test',
            'template_id' => $template->id,
        ]);

        $action = new LintCampaignDeliverabilityAction;
        $result = $action->execute($campaign);

        $this->assertGreaterThanOrEqual(90, $result['score']);
        $this->assertSame('excellent', $result['status']);
        $this->assertEmpty($result['warnings']);
        $this->assertContains('Unsubscribe mechanism is present.', $result['passed_checks']);
        $this->assertContains('Preview text is configured to complement the subject line.', $result['passed_checks']);
    }
}
