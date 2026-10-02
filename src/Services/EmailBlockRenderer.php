<?php

declare(strict_types=1);

namespace Odden\Marketing\Services;

class EmailBlockRenderer
{
    /**
     * Render an array of modular email blocks into responsive, bulletproof HTML.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function render(array $blocks): string
    {
        $content = '';
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? 'text');
            $content .= match ($type) {
                'hero' => $this->renderHero($block),
                'columns' => $this->renderColumns($block),
                'features' => $this->renderFeatures($block),
                'testimonial' => $this->renderTestimonial($block),
                'cta' => $this->renderCta($block),
                'footer' => $this->renderFooter($block),
                default => $this->renderText($block),
            };
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    </style>
</head>
<body style="margin: 0; padding: 24px 0; background-color: #f8fafc;">
    <center>
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">
            <tr>
                <td style="padding: 0;">
                    {$content}
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
    }

    /**
     * Render a Hero Section Block.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderHero(array $block): string
    {
        $title = htmlspecialchars((string) ($block['title'] ?? 'Announcing Something Big'));
        $subtitle = htmlspecialchars((string) ($block['subtitle'] ?? ''));
        $buttonText = htmlspecialchars((string) ($block['button_text'] ?? ''));
        $buttonUrl = htmlspecialchars((string) ($block['button_url'] ?? '#'));
        $bgColor = htmlspecialchars((string) ($block['bg_color'] ?? '#0f172a'));
        $textColor = htmlspecialchars((string) ($block['text_color'] ?? '#ffffff'));

        $buttonHtml = $buttonText !== '' ? <<<HTML
            <tr>
                <td align="center" style="padding-top: 24px;">
                    <a href="{$buttonUrl}" style="display: inline-block; background-color: #38bdf8; color: #0f172a; font-weight: 600; font-size: 15px; padding: 12px 28px; border-radius: 8px; text-decoration: none;">
                        {$buttonText}
                    </a>
                </td>
            </tr>
HTML : '';

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color: {$bgColor}; color: {$textColor}; padding: 48px 32px; text-align: center;">
            <tr>
                <td align="center">
                    <h1 style="margin: 0; font-size: 28px; font-weight: 800; line-height: 1.25; color: {$textColor};">
                        {$title}
                    </h1>
                    <p style="margin: 12px 0 0 0; font-size: 16px; line-height: 1.5; color: #cbd5e1; max-width: 480px;">
                        {$subtitle}
                    </p>
                </td>
            </tr>
            {$buttonHtml}
        </table>
HTML;
    }

    /**
     * Render a Two-Column Content Block.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderColumns(array $block): string
    {
        $leftTitle = htmlspecialchars((string) ($block['left_title'] ?? ''));
        $leftBody = nl2br(htmlspecialchars((string) ($block['left_body'] ?? '')));
        $rightTitle = htmlspecialchars((string) ($block['right_title'] ?? ''));
        $rightBody = nl2br(htmlspecialchars((string) ($block['right_body'] ?? '')));

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 32px 24px;">
            <tr>
                <td width="50%" valign="top" style="padding: 0 12px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 18px; color: #0f172a; font-weight: 700;">{$leftTitle}</h3>
                    <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #475569;">{$leftBody}</p>
                </td>
                <td width="50%" valign="top" style="padding: 0 12px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 18px; color: #0f172a; font-weight: 700;">{$rightTitle}</h3>
                    <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #475569;">{$rightBody}</p>
                </td>
            </tr>
        </table>
HTML;
    }

    /**
     * Render a 3-Item Feature Grid Block.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderFeatures(array $block): string
    {
        /** @var list<array{icon?: string, title?: string, text?: string}> $items */
        $items = $block['items'] ?? [];
        $rows = '';

        foreach ($items as $item) {
            $icon = htmlspecialchars($item['icon'] ?? '⚡');
            $itemTitle = htmlspecialchars($item['title'] ?? '');
            $itemText = htmlspecialchars($item['text'] ?? '');

            $rows .= <<<HTML
            <tr>
                <td width="36" valign="top" style="padding: 12px 16px 12px 0; font-size: 20px;">{$icon}</td>
                <td valign="top" style="padding: 12px 0;">
                    <strong style="color: #0f172a; font-size: 15px; display: block; margin-bottom: 4px;">{$itemTitle}</strong>
                    <span style="color: #64748b; font-size: 14px; line-height: 1.5;">{$itemText}</span>
                </td>
            </tr>
HTML;
        }

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 24px 32px; background-color: #f1f5f9;">
            {$rows}
        </table>
HTML;
    }

    /**
     * Render a Customer Testimonial Card Block.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderTestimonial(array $block): string
    {
        $quote = htmlspecialchars((string) ($block['quote'] ?? ''));
        $author = htmlspecialchars((string) ($block['author'] ?? ''));
        $role = htmlspecialchars((string) ($block['role'] ?? ''));
        $company = htmlspecialchars((string) ($block['company'] ?? ''));

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 32px;">
            <tr>
                <td style="background-color: #f8fafc; border-left: 4px solid #38bdf8; border-radius: 6px; padding: 20px 24px;">
                    <p style="margin: 0 0 12px 0; font-size: 15px; line-height: 1.6; font-style: italic; color: #334155;">
                        “{$quote}”
                    </p>
                    <span style="font-size: 13px; font-weight: 700; color: #0f172a;">{$author}</span>
                    <span style="font-size: 13px; color: #64748b;"> &middot; {$role}, {$company}</span>
                </td>
            </tr>
        </table>
HTML;
    }

    /**
     * Render a Call to Action Box.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderCta(array $block): string
    {
        $heading = htmlspecialchars((string) ($block['heading'] ?? 'Ready to get started?'));
        $text = htmlspecialchars((string) ($block['text'] ?? ''));
        $buttonText = htmlspecialchars((string) ($block['button_text'] ?? 'Get Started'));
        $buttonUrl = htmlspecialchars((string) ($block['button_url'] ?? '#'));

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 32px; text-align: center;">
            <tr>
                <td style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 32px 24px;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 700; color: #166534;">{$heading}</h3>
                    <p style="margin: 8px 0 20px 0; font-size: 14px; color: #15803d;">{$text}</p>
                    <a href="{$buttonUrl}" style="display: inline-block; background-color: #16a34a; color: #ffffff; font-weight: 600; font-size: 14px; padding: 12px 24px; border-radius: 8px; text-decoration: none;">
                        {$buttonText}
                    </a>
                </td>
            </tr>
        </table>
HTML;
    }

    /**
     * Render a standard Text / Markdown Block.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderText(array $block): string
    {
        $html = (string) ($block['content'] ?? '');

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 24px 32px;">
            <tr>
                <td style="font-size: 15px; line-height: 1.6; color: #334155;">
                    {$html}
                </td>
            </tr>
        </table>
HTML;
    }

    /**
     * Render a Footer Section Block with Unsubscribe Link.
     *
     * @param  array<string, mixed>  $block
     */
    public function renderFooter(array $block): string
    {
        $companyName = htmlspecialchars((string) ($block['company_name'] ?? 'Odden'));
        $address = htmlspecialchars((string) ($block['address'] ?? '123 Market St, San Francisco, CA'));
        $unsubscribeUrl = (string) ($block['unsubscribe_url'] ?? '{{unsubscribe_url}}');

        return <<<HTML
        <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="padding: 24px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;">
            <tr>
                <td style="font-size: 12px; line-height: 1.5; color: #94a3b8;">
                    &copy; 2026 {$companyName}. All rights reserved.<br>
                    {$address}<br><br>
                    <a href="{$unsubscribeUrl}" style="color: #64748b; text-decoration: underline;">
                        Unsubscribe or manage your email preferences
                    </a>
                </td>
            </tr>
        </table>
HTML;
    }

    /**
     * Get a pre-designed modular email preset.
     *
     * @return list<array<string, mixed>>
     */
    public function getPreset(string $name): array
    {
        return match ($name) {
            'product_launch' => [
                [
                    'type' => 'hero',
                    'title' => 'Introducing Odden 2.0 🚀',
                    'subtitle' => 'The fastest, modern, open CRM and Marketing engine built for high-growth teams.',
                    'button_text' => 'Explore the Release',
                    'button_url' => 'https://odden.test/features',
                    'bg_color' => '#0f172a',
                ],
                [
                    'type' => 'features',
                    'items' => [
                        ['icon' => '⚡', 'title' => 'Real-Time Pipeline Velocity', 'text' => 'Track deals from first click through closed-won with millisecond precision.'],
                        ['icon' => '🎯', 'title' => 'Automated Lead Scoring', 'text' => 'Identify MQLs automatically with behavioral signals and decay models.'],
                        ['icon' => '💬', 'title' => 'Omnichannel SMS & Drips', 'text' => 'Nurture leads across email, SMS, and webhook journeys in one workflow.'],
                    ],
                ],
                [
                    'type' => 'testimonial',
                    'quote' => 'Odden replaced our entire HubSpot stack in less than two weeks, cutting our SaaS spend by 80%.',
                    'author' => 'Sarah Connor',
                    'role' => 'VP of Growth',
                    'company' => 'Cyberdyne Systems',
                ],
                [
                    'type' => 'cta',
                    'heading' => 'Ready to upgrade your revenue engine?',
                    'text' => 'Join thousands of companies scaling with Odden.',
                    'button_text' => 'Book a Live Demo',
                    'button_url' => 'https://odden.test/demo',
                ],
                [
                    'type' => 'footer',
                    'company_name' => 'Odden Marketing',
                    'address' => '548 Market St, San Francisco, CA 94104',
                ],
            ],
            default => [
                [
                    'type' => 'hero',
                    'title' => '{{campaign.subject}}',
                    'subtitle' => 'Latest news and updates from {{company.name}}.',
                    'button_text' => 'Read Full Story',
                    'button_url' => 'https://odden.test',
                ],
                [
                    'type' => 'text',
                    'content' => '<p>Hi {{contact.first_name}},</p><p>We are thrilled to share our latest product briefing with you.</p>',
                ],
                [
                    'type' => 'footer',
                    'company_name' => 'Odden',
                    'address' => 'San Francisco, CA',
                ],
            ],
        };
    }

    /**
     * Render a preset into full HTML.
     */
    public function renderPreset(string $name): string
    {
        return $this->render($this->getPreset($name));
    }
}
