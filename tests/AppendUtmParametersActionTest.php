<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Odden\Marketing\Actions\AppendUtmParametersAction;
use Odden\Marketing\Models\Campaign;

class AppendUtmParametersActionTest extends TestCase
{
    public function test_appends_utm_parameters_to_clean_url(): void
    {
        $campaign = new Campaign([
            'name' => 'Q4 Enterprise Launch',
            'utm_auto_tag' => true,
        ]);

        $action = new AppendUtmParametersAction;
        $url = 'https://acme.com/pricing';

        $tagged = $action->execute($url, $campaign, 'A');

        $this->assertStringContainsString('utm_source=odden', $tagged);
        $this->assertStringContainsString('utm_medium=email', $tagged);
        $this->assertStringContainsString('utm_campaign=q4-enterprise-launch', $tagged);
        $this->assertStringContainsString('utm_content=variant_a', $tagged);
    }

    public function test_preserves_existing_query_params_and_anchor_fragments(): void
    {
        $campaign = new Campaign([
            'name' => 'Summer Promo',
            'utm_campaign' => 'summer-deal',
            'utm_auto_tag' => true,
        ]);

        $action = new AppendUtmParametersAction;
        $url = 'https://acme.com/shop?ref=partner&discount=20#features';

        $tagged = $action->execute($url, $campaign);

        $this->assertStringContainsString('ref=partner', $tagged);
        $this->assertStringContainsString('discount=20', $tagged);
        $this->assertStringContainsString('utm_campaign=summer-deal', $tagged);
        $this->assertStringEndsWith('#features', $tagged);
    }

    public function test_skips_internal_mailto_tel_and_unsubscribe_links(): void
    {
        $campaign = new Campaign([
            'name' => 'Newsletter',
            'utm_auto_tag' => true,
        ]);

        $action = new AppendUtmParametersAction;

        $this->assertSame('mailto:sales@acme.com', $action->execute('mailto:sales@acme.com', $campaign));
        $this->assertSame('tel:+15551234567', $action->execute('tel:+15551234567', $campaign));
        $this->assertSame('#top', $action->execute('#top', $campaign));
        $this->assertSame('https://odden.test/marketing/unsubscribe/tok123', $action->execute('https://odden.test/marketing/unsubscribe/tok123', $campaign));
    }

    public function test_appends_utms_across_full_html_email_body(): void
    {
        $campaign = new Campaign([
            'name' => 'Product Brief',
            'utm_auto_tag' => true,
        ]);

        $html = '<p>Check out our <a href="https://acme.com/features" class="btn">New Features</a> and our <a href="https://acme.com/blog">Blog</a>.</p>';

        $action = new AppendUtmParametersAction;
        $processed = $action->appendHtmlLinks($html, $campaign, 'B');

        $this->assertStringContainsString('https://acme.com/features?utm_source=odden', $processed);
        $this->assertStringContainsString('utm_content=variant_b', $processed);
        $this->assertStringContainsString('class="btn"', $processed);
        $this->assertStringContainsString('https://acme.com/blog?utm_source=odden', $processed);
    }
}
