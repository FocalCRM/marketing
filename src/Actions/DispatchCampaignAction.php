<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Support\Collection;

class DispatchCampaignAction
{
    /**
     * Dispatch an email marketing campaign to its targeted list audience or A/B test sample.
     *
     * @param  Collection<int, Contact>|null  $explicitContacts
     * @return array{total_recipients: int, delivered_count: int, suppressed_count: int}
     */
    public function execute(Campaign $campaign, ?Collection $explicitContacts = null): array
    {
        $campaign->update(['status' => CampaignStatus::Sending]);

        // Resolve audience list (static or dynamic active list)
        $audienceList = $campaign->crmList ?? $campaign->list;
        if ($audienceList !== null) {
            $audienceList->syncActiveMembers();
            /** @var Collection<int, Contact> $contacts */
            $contacts = $explicitContacts ?? $audienceList->contacts()->get();
        } else {
            /** @var Collection<int, Contact> $contacts */
            $contacts = $explicitContacts ?? Contact::all();
        }

        $compiler = app(CompileCampaignMessageAction::class);
        $deliveredCount = 0;
        $suppressedCount = 0;

        // A/B Split Testing Mode
        if ($campaign->is_ab_test) {
            $eligible = $contacts->filter(function (Contact $c) use ($campaign): bool {
                $e = mb_strtolower(trim($c->email));

                if (empty($e) || MarketingSubscription::isSuppressed($e, $campaign->topic_id)) {
                    return false;
                }

                if (! empty($campaign->topic) && ! $c->isSubscribedToTopic((string) $campaign->topic)) {
                    return false;
                }

                return true;
            })->values();

            $totalRecipients = $eligible->count();
            $suppressedCount = $contacts->count() - $totalRecipients;

            $samplePct = $campaign->ab_test_sample_percentage ?: 20;
            $sampleTotal = min($totalRecipients, max(2, (int) round($totalRecipients * ($samplePct / 100))));
            if ($sampleTotal % 2 !== 0 && $sampleTotal < $totalRecipients) {
                $sampleTotal++;
            }

            $halfSample = (int) ($sampleTotal / 2);
            $variantAContacts = $eligible->slice(0, $halfSample);
            $variantBContacts = $eligible->slice($halfSample, $halfSample);
            $remainingContacts = $eligible->slice($sampleTotal);

            // Send Variant A
            foreach ($variantAContacts as $contact) {
                $this->dispatchToRecipient($campaign, $contact, 'A', $compiler);
                $deliveredCount++;
            }

            // Send Variant B
            foreach ($variantBContacts as $contact) {
                $this->dispatchToRecipient($campaign, $contact, 'B', $compiler);
                $deliveredCount++;
            }

            // Stage remaining recipients pending winner evaluation
            foreach ($remainingContacts as $contact) {
                $campaign->recipients()->create([
                    'contact_id' => $contact->id,
                    'email' => mb_strtolower(trim($contact->email)),
                    'status' => RecipientStatus::Pending,
                    'variant' => null,
                ]);
            }

            $campaign->update([
                'status' => CampaignStatus::Sending,
                'sent_at' => now(),
                'total_recipients' => $totalRecipients,
                'delivered_count' => $deliveredCount,
            ]);

            return [
                'total_recipients' => $totalRecipients,
                'delivered_count' => $deliveredCount,
                'suppressed_count' => $suppressedCount,
            ];
        }

        // Standard Full Broadcast Mode
        $totalRecipients = $contacts->count();

        foreach ($contacts as $contact) {
            $email = mb_strtolower(trim($contact->email));

            if (empty($email) || MarketingSubscription::isSuppressed($email, $campaign->topic_id)) {
                $suppressedCount++;

                continue;
            }

            if (! empty($campaign->topic) && ! $contact->isSubscribedToTopic((string) $campaign->topic)) {
                $suppressedCount++;

                continue;
            }

            // Check Send Frequency Capping / Fatigue Protection
            if (config('focal-marketing.fatigue_protection.enabled', false)) {
                $fatigueCheck = app(CheckFatiguePolicyAction::class)->execute($contact);
                if (! $fatigueCheck['can_send']) {
                    $suppressedCount++;

                    continue;
                }
            }

            $useTimezoneSending = $campaign->send_in_recipient_timezone || $campaign->send_by_timezone || $campaign->use_sto;

            if ($useTimezoneSending) {
                $targetTime = $campaign->calculateScheduledTimeForContact($contact);
                if ($targetTime->isFuture() && $targetTime->diffInMinutes(now()) > 5) {
                    $campaign->recipients()->create([
                        'contact_id' => $contact->id,
                        'email' => $email,
                        'status' => RecipientStatus::Pending,
                        'variant' => null,
                        'scheduled_send_at' => $targetTime,
                    ]);

                    continue;
                }
            }

            $this->dispatchToRecipient($campaign, $contact, null, $compiler);
            $deliveredCount++;
        }

        $allDelivered = $deliveredCount === ($totalRecipients - $suppressedCount);

        $campaign->update([
            'status' => $allDelivered ? CampaignStatus::Sent : CampaignStatus::Sending,
            'sent_at' => now(),
            'total_recipients' => $totalRecipients,
            'delivered_count' => $deliveredCount,
        ]);

        return [
            'total_recipients' => $totalRecipients,
            'delivered_count' => $deliveredCount,
            'suppressed_count' => $suppressedCount,
        ];
    }

    /**
     * Dispatch individualized email to a single recipient.
     */
    protected function dispatchToRecipient(
        Campaign $campaign,
        Contact $contact,
        ?string $variant,
        CompileCampaignMessageAction $compiler
    ): CampaignRecipient {
        $email = mb_strtolower(trim($contact->email));

        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $email,
            'status' => RecipientStatus::Sent,
            'variant' => $variant,
            'sent_at' => now(),
        ]);

        $compiler->execute($campaign, $recipient);

        $subject = ($variant === 'B' && ! empty($campaign->variant_b_subject))
            ? $campaign->variant_b_subject
            : $campaign->subject;

        $contact->logTask(
            title: "Marketing Campaign: {$campaign->name}".($variant ? " (Variant {$variant})" : ''),
            dueAt: now(),
            body: "Delivered email with subject: \"{$subject}\""
        );

        $contact->updateQuietly(['last_marketing_email_sent_at' => now()]);

        return $recipient;
    }
}
