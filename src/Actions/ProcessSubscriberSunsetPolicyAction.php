<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProcessSubscriberSunsetPolicyAction
{
    /**
     * Identify dormant email subscribers who have received multiple marketing broadcasts
     * but have zero opens or clicks over the inactivity window, and either flag them or
     * automatically suppress them to safeguard domain sender reputation.
     *
     * @return array{
     *     dormant_evaluated_count: int,
     *     dormant_detected_count: int,
     *     auto_suppressed_count: int,
     *     contact_ids: list<int>
     * }
     */
    public function execute(
        int $inactivityDays = 90,
        int $minSendsReceived = 3,
        bool $autoSuppress = false
    ): array {
        $thresholdDate = now()->subDays($inactivityDays);

        /** @var Collection<int, Contact> $candidates */
        $candidates = Contact::query()
            ->whereNotNull('last_marketing_email_sent_at')
            ->where('last_marketing_email_sent_at', '<=', $thresholdDate)
            ->get();

        $dormantDetected = [];
        $suppressedCount = 0;

        foreach ($candidates as $contact) {
            $email = mb_strtolower(trim($contact->email));

            // Skip if already unsubscribed or suppressed
            if (empty($email) || MarketingSubscription::isSuppressed($email)) {
                continue;
            }

            // Check total emails received
            $totalSends = CampaignRecipient::query()
                ->where('contact_id', $contact->id)
                ->where('sent_at', '<=', now())
                ->count();

            if ($totalSends < $minSendsReceived) {
                continue;
            }

            // Check if ANY engagement occurred within the dormancy window
            $recentEngagement = CampaignRecipient::query()
                ->where('contact_id', $contact->id)
                ->where(function ($q) use ($thresholdDate): void {
                    $q->where('opened_at', '>=', $thresholdDate)
                        ->orWhere('clicked_at', '>=', $thresholdDate);
                })
                ->exists();

            if ($recentEngagement) {
                continue;
            }

            // Contact is confirmed dormant
            $dormantDetected[] = $contact->id;

            DB::transaction(function () use ($contact, $email, $autoSuppress, $inactivityDays, &$suppressedCount): void {
                $props = $contact->properties ?? [];

                if ($autoSuppress) {
                    MarketingSubscription::unsubscribe($email, $contact->id);

                    $props['sunset_suppressed'] = true;
                    $props['sunset_suppressed_at'] = now()->toIso8601String();
                    $suppressedCount++;

                    $contact->logTask(
                        title: 'Subscriber Sunset Protection Applied',
                        dueAt: now(),
                        body: "Contact automatically suppressed after {$inactivityDays} days of dormancy without email opens or clicks."
                    );
                } else {
                    $props['is_sunset_dormant'] = true;
                    $props['sunset_dormant_detected_at'] = now()->toIso8601String();
                }

                $contact->updateQuietly(['properties' => $props]);
            });
        }

        return [
            'dormant_evaluated_count' => $candidates->count(),
            'dormant_detected_count' => count($dormantDetected),
            'auto_suppressed_count' => $suppressedCount,
            'contact_ids' => $dormantDetected,
        ];
    }
}
