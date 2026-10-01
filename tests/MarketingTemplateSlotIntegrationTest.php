<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use DoPHP\MailBuilder\Filament\Components\EmailSlotBuilder;
use Filament\Forms\Components\Builder;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MarketingTemplateSlotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_marketing_template_with_slots_auto_compiles_html_and_text(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Automated Slot Template',
            'subject' => 'Welcome to Focal Slots',
            'preview_text' => 'High conversion emails made easy',
            'category' => 'onboarding',
            'slots' => [
                [
                    'type' => 'header',
                    'data' => [
                        'brand_name' => 'Focal Mailer',
                    ],
                ],
                [
                    'type' => 'hero',
                    'data' => [
                        'title' => 'Hello from Slots!',
                        'subtitle' => 'This was compiled automatically from structured JSON slots.',
                        'button_text' => 'Get Started',
                        'button_url' => 'https://focal.test/app',
                    ],
                ],
                [
                    'type' => 'footer',
                    'data' => [
                        'company_name' => 'Focal Global',
                        'address' => 'San Francisco, CA',
                    ],
                ],
            ],
            'body_html' => '', // Empty on purpose to test auto-compilation
        ]);

        $this->assertNotEmpty($template->body_html);
        $this->assertStringContainsString('<!DOCTYPE html>', $template->body_html);
        $this->assertStringContainsString('Focal Mailer', $template->body_html);
        $this->assertStringContainsString('Hello from Slots!', $template->body_html);
        $this->assertStringContainsString('https://focal.test/app', $template->body_html);

        $this->assertNotEmpty($template->body_text);
        $this->assertIsString($template->body_text);
        $this->assertStringContainsString('Hello from Slots!', $template->body_text);
        $this->assertStringContainsString('>> Get Started: https://focal.test/app', $template->body_text);
    }

    public function test_saving_raw_html_auto_extracts_plain_text_fallback(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'HTML Only Template',
            'subject' => 'Raw Code',
            'category' => 'general',
            'body_html' => '<h2>Special Offer</h2><p>Click <a href="https://focal.test/buy">here to claim</a> your credit.</p>',
        ]);

        $this->assertNotNull($template->body_text);
        $this->assertIsString($template->body_text);
        $this->assertStringContainsString('Special Offer', $template->body_text);
        $this->assertStringContainsString('here to claim (https://focal.test/buy)', $template->body_text);
    }

    public function test_email_slot_builder_filament_component_schema(): void
    {
        $builder = EmailSlotBuilder::make('slots');

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertSame('slots', $builder->getName());

        $this->assertSame('header', EmailSlotBuilder::getHeaderBlock()->getName());
        $this->assertSame('hero', EmailSlotBuilder::getHeroBlock()->getName());
        $this->assertSame('body_text', EmailSlotBuilder::getBodyTextBlock()->getName());
        $this->assertSame('button', EmailSlotBuilder::getButtonBlock()->getName());
        $this->assertSame('two_column', EmailSlotBuilder::getTwoColumnBlock()->getName());
        $this->assertSame('features', EmailSlotBuilder::getFeaturesBlock()->getName());
        $this->assertSame('testimonial', EmailSlotBuilder::getTestimonialBlock()->getName());
        $this->assertSame('stat_box', EmailSlotBuilder::getStatBoxBlock()->getName());
        $this->assertSame('divider', EmailSlotBuilder::getDividerBlock()->getName());
        $this->assertSame('social_links', EmailSlotBuilder::getSocialLinksBlock()->getName());
        $this->assertSame('footer', EmailSlotBuilder::getFooterBlock()->getName());
        $this->assertSame('html', EmailSlotBuilder::getHtmlBlock()->getName());
        $this->assertSame('image_banner', EmailSlotBuilder::getImageBannerBlock()->getName());
        $this->assertSame('video_card', EmailSlotBuilder::getVideoCardBlock()->getName());
        $this->assertSame('pricing_grid', EmailSlotBuilder::getPricingGridBlock()->getName());
        $this->assertSame('rating_bar', EmailSlotBuilder::getRatingBarBlock()->getName());
        $this->assertSame('countdown_timer', EmailSlotBuilder::getCountdownTimerBlock()->getName());
        $this->assertSame('accordion', EmailSlotBuilder::getAccordionBlock()->getName());
    }

    public function test_template_applies_custom_theme_and_detects_ab_test(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Themed Brand Template',
            'subject' => 'Variant A Subject',
            'subject_variant_b' => 'Variant B Subject',
            'slots_variant_b' => [
                ['type' => 'hero', 'data' => ['title' => 'Variant B Hero', 'subtitle' => 'Different copy']],
            ],
            'theme' => [
                'primary_color' => '#7c3aed',
                'container_width' => 640,
            ],
            'slots' => [
                ['type' => 'button', 'data' => ['text' => 'Purple CTA', 'url' => 'https://focal.test']],
            ],
        ]);

        $this->assertTrue($template->hasAbTest());
        $this->assertIsArray($template->theme);
        $this->assertSame('#7c3aed', $template->theme['primary_color']);
        $this->assertStringContainsString('#7c3aed', $template->body_html);
        $this->assertStringContainsString('640', $template->body_html);

        // Check variant B compilation
        $this->assertNotEmpty($template->body_html_variant_b);
        $this->assertStringContainsString('Variant B Hero', (string) $template->body_html_variant_b);
        $this->assertSame('Variant B Subject', $template->getVariantSubject('B'));
        $this->assertSame('Variant A Subject', $template->getVariantSubject('A'));
        $this->assertSame($template->body_html, $template->getVariantHtml('A'));
        $this->assertSame($template->body_html_variant_b, $template->getVariantHtml('B'));
    }
}
