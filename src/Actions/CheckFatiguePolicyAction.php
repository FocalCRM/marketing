<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Carbon\CarbonInterface;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\CampaignRecipient;

class CheckFatiguePolicyAction
{
    /**
     * Default fatigue limits: max 2 emails per 7 days, min 24 hours between sends.
     */
    protected const MAX_EMAILS_PER_7_DAYS = 2;

    protected const MIN_HOURS_BETWEEN_SENDS = 24;

    /**
     * Check if a contact is within fatigue limits and can receive a new marketing email.
     *
     * @return array{can_send: bool, reason: ?string, next_available_at: ?CarbonInterface}
     */
    public function execute(Contact $contact): array
    {
        // 0. Check deliverability sunset policy suppression
        if ($contact->sunset_stage === 'suppressed') {
            return [
                'can_send' => false,
                'reason' => 'Contact suppressed under deliverability sunset policy',
                'next_available_at' => null,
            ];
        }

        $minHours = (int) config('odden-marketing.fatigue_protection.min_hours_between_sends', self::MIN_HOURS_BETWEEN_SENDS);
        $max7Days = (int) config('odden-marketing.fatigue_protection.max_emails_per_7_days', self::MAX_EMAILS_PER_7_DAYS);

        // 1. Check minimum interval since last send
        if ($contact->last_marketing_email_sent_at !== null) {
            $hoursSinceLastSend = $contact->last_marketing_email_sent_at->diffInHours(now());
            if ($hoursSinceLastSend < $minHours) {
                $nextAvailable = $contact->last_marketing_email_sent_at->copy()->addHours($minHours);

                return [
                    'can_send' => false,
                    'reason' => "Contact received marketing email {$hoursSinceLastSend}h ago (minimum interval: {$minHours}h)",
                    'next_available_at' => $nextAvailable,
                ];
            }
        }

        // 2. Check 7-day rolling send volume
        $sendsLast7Days = CampaignRecipient::query()
            ->where('contact_id', $contact->id)
            ->where('status', '!=', RecipientStatus::Pending->value)
            ->where('sent_at', '>=', now()->subDays(7))
            ->count();

        if ($sendsLast7Days >= $max7Days) {
            return [
                'can_send' => false,
                'reason' => "Contact reached weekly communication cap ({$sendsLast7Days}/{$max7Days} sends in last 7 days)",
                'next_available_at' => now()->addDays(2),
            ];
        }

        return [
            'can_send' => true,
            'reason' => null,
            'next_available_at' => null,
        ];
    }
}
