<?php

declare(strict_types=1);

namespace Focal\Marketing\Services;

use DoPHP\MailBuilder\Data\EmailSlot;
use DoPHP\MailBuilder\Enums\SlotType;
use DoPHP\MailBuilder\MailBuilder;
use Focal\Core\Models\Contact;
use Focal\Marketing\Models\MarketingTemplate;

class ContactPersonaPreviewService
{
    /**
     * Synthetic CRM test personas for instant preview testing.
     *
     * @var array<string, array{name: string, context: array<string, mixed>}>
     */
    public const SYNTHETIC_PERSONAS = [
        'vip_customer' => [
            'name' => 'Sophia Laurent (VIP Customer)',
            'context' => [
                'contact.first_name' => 'Sophia',
                'contact.last_name' => 'Laurent',
                'contact.email' => 'sophia.laurent@enterprise.com',
                'contact.lifecycle_stage' => 'customer',
                'contact.job_title' => 'Chief Technology Officer',
                'contact.is_vip' => true,
                'company.name' => 'Acme Global',
                'company.industry' => 'Enterprise Software',
                'total_spent' => '$12,450.00',
            ],
        ],
        'trial_user' => [
            'name' => 'Marcus Vance (Active Free Trial)',
            'context' => [
                'contact.first_name' => 'Marcus',
                'contact.last_name' => 'Vance',
                'contact.email' => 'marcus@startuplab.io',
                'contact.lifecycle_stage' => 'lead',
                'contact.job_title' => 'Product Lead',
                'contact.is_vip' => false,
                'company.name' => 'StartupLab',
                'company.industry' => 'FinTech',
                'trial_days_left' => '3',
            ],
        ],
        'churn_risk' => [
            'name' => 'Elena Rostova (At-Risk Account)',
            'context' => [
                'contact.first_name' => 'Elena',
                'contact.last_name' => 'Rostova',
                'contact.email' => 'elena@biotech.org',
                'contact.lifecycle_stage' => 'customer',
                'contact.job_title' => 'Operations Director',
                'contact.is_vip' => false,
                'company.name' => 'BioTech Research',
                'company.industry' => 'Healthcare',
                'inactive_days' => '45',
            ],
        ],
    ];

    /**
     * Render a template preview using a specific CRM Contact or synthetic persona context.
     *
     * @param  MarketingTemplate|list<array<string, mixed>>  $templateOrSlots
     * @return array{
     *     persona_label: string,
     *     context: array<string, mixed>,
     *     compiled_html: string,
     *     plain_text: string,
     *     total_slots: int,
     *     visible_slots_count: int,
     *     hidden_slots_count: int
     * }
     */
    public static function preview(
        MarketingTemplate|array $templateOrSlots,
        int|Contact|string $personaOrContact
    ): array {
        $context = [];
        $personaLabel = 'Default Contact';

        if ($personaOrContact instanceof Contact) {
            $context = self::buildContactContext($personaOrContact);
            $personaLabel = "{$personaOrContact->first_name} {$personaOrContact->last_name} ({$personaOrContact->email})";
        } elseif (is_numeric($personaOrContact)) {
            $contact = Contact::query()->with('companies')->find((int) $personaOrContact);
            if ($contact !== null) {
                $context = self::buildContactContext($contact);
                $personaLabel = "{$contact->first_name} {$contact->last_name} ({$contact->email})";
            }
        } elseif (isset(self::SYNTHETIC_PERSONAS[$personaOrContact])) {
            $persona = self::SYNTHETIC_PERSONAS[$personaOrContact];
            $context = $persona['context'];
            $personaLabel = $persona['name'];
        }

        $slots = is_array($templateOrSlots)
            ? $templateOrSlots
            : ($templateOrSlots->slots ?? []);

        $theme = ($templateOrSlots instanceof MarketingTemplate)
            ? ($templateOrSlots->theme ?? [])
            : [];

        $subject = ($templateOrSlots instanceof MarketingTemplate)
            ? ($templateOrSlots->subject ?? 'Important Update')
            : 'Important Update';

        // Count visible and hidden slots
        $totalSlots = count($slots);
        $visibleCount = 0;
        $hiddenCount = 0;

        foreach ($slots as $rawSlot) {
            $typeStr = (string) ($rawSlot['type'] ?? 'body_text');
            $slotType = SlotType::tryFrom($typeStr) ?? SlotType::BodyText;
            $data = is_array($rawSlot['data'] ?? null) ? $rawSlot['data'] : [];
            $visibility = is_array($rawSlot['visibility'] ?? null) ? $rawSlot['visibility'] : null;

            $slotObj = new EmailSlot($slotType, $data, $visibility);
            if ($slotObj->matchesContext($context)) {
                $visibleCount++;
            } else {
                $hiddenCount++;
            }
        }

        $compiledHtml = MailBuilder::compile($slots, [
            'context' => $context,
            'theme' => $theme,
            'subject' => $subject,
            'interpolate' => true,
        ]);

        $plainText = MailBuilder::plainText($compiledHtml);

        return [
            'persona_label' => $personaLabel,
            'context' => $context,
            'compiled_html' => $compiledHtml,
            'plain_text' => $plainText,
            'total_slots' => $totalSlots,
            'visible_slots_count' => $visibleCount,
            'hidden_slots_count' => $hiddenCount,
        ];
    }

    /**
     * Extract flattened merge tag context from a Contact model.
     *
     * @return array<string, mixed>
     */
    protected static function buildContactContext(Contact $contact): array
    {
        $context = [
            'contact.id' => $contact->id,
            'contact.first_name' => $contact->first_name,
            'contact.last_name' => $contact->last_name,
            'contact.email' => $contact->email,
            'contact.lifecycle_stage' => $contact->lifecycle_stage->value,
            'contact.job_title' => $contact->job_title ?? '',
            'contact.phone' => $contact->phone ?? '',
        ];

        if ($contact->relationLoaded('companies')) {
            $company = $contact->companies->first();
            if ($company !== null) {
                $context['company.name'] = $company->name;
                $context['company.industry'] = $company->industry ?? '';
                $context['company.domain'] = $company->domain ?? '';
            }
        }

        return $context;
    }
}
