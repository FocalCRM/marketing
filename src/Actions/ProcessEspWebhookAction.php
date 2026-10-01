<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Marketing\Enums\LeadScoringEventType;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Enums\SubscriptionStatus;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\EmailSuppression;
use Focal\Marketing\Models\EspEvent;
use Focal\Marketing\Models\MarketingSubscription;

class ProcessEspWebhookAction
{
    /**
     * Process an incoming email service provider deliverability webhook.
     *
     * @param  array<string|int, mixed>  $payload
     */
    public function execute(string $provider, array $payload): EspEvent
    {
        $normalized = $this->normalizePayload($provider, $payload);

        $email = mb_strtolower(trim($normalized['email']));
        $eventType = $normalized['event_type'];

        /** @var CampaignRecipient|null $recipient */
        $recipient = null;
        if (! empty($normalized['tracking_token'])) {
            $recipient = CampaignRecipient::query()->where('tracking_token', $normalized['tracking_token'])->first();
        }
        if ($recipient === null && ! empty($email)) {
            $recipient = CampaignRecipient::query()
                ->where('email', $email)
                ->latest('id')
                ->first();
        }

        /** @var EspEvent $event */
        $event = EspEvent::create([
            'provider' => $provider,
            'event_type' => $eventType,
            'email' => $email,
            'campaign_id' => $recipient?->campaign_id,
            'recipient_id' => $recipient?->id,
            'error_code' => $normalized['error_code'] ?? null,
            'error_message' => $normalized['error_message'] ?? null,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        // Auto-suppress and handle bounces or spam complaints
        if (in_array($eventType, ['bounce', 'hard_bounce', 'complaint', 'spam', 'unsubscribed'], true)) {
            $status = in_array($eventType, ['bounce', 'hard_bounce'], true)
                ? SubscriptionStatus::Bounced
                : SubscriptionStatus::Unsubscribed;

            MarketingSubscription::updateOrCreate(
                ['email' => $email],
                [
                    'contact_id' => $recipient?->contact_id,
                    'status' => $status,
                    'unsubscribed_at' => now(),
                ]
            );

            // Register in global deliverability suppression registry
            $suppressionReason = match ($eventType) {
                'complaint', 'spam' => 'spam_complaint',
                'unsubscribed' => 'unsubscribe',
                default => 'hard_bounce',
            };
            EmailSuppression::suppress(
                email: $email,
                reason: $suppressionReason,
                source: "esp_webhook:{$provider}",
                metadata: [
                    'campaign_id' => $recipient?->campaign_id,
                    'error_code' => $normalized['error_code'] ?? null,
                    'error_message' => $normalized['error_message'] ?? null,
                ]
            );

            if ($recipient !== null) {
                $recipientStatus = ($status === SubscriptionStatus::Bounced)
                    ? RecipientStatus::Bounced
                    : RecipientStatus::Unsubscribed;

                $recipient->update(['status' => $recipientStatus]);

                if ($recipientStatus === RecipientStatus::Bounced) {
                    $recipient->campaign->increment('bounces_count');
                } elseif ($recipientStatus === RecipientStatus::Unsubscribed) {
                    $recipient->campaign->increment('unsubscribes_count');
                }

                // Deduct lead scoring if spam complaint or hard bounce
                if ($recipient->contact !== null) {
                    app(ApplyLeadScoringEventAction::class)->execute(
                        contact: $recipient->contact,
                        eventType: LeadScoringEventType::Unsubscribed,
                        description: "ESP Deliverability Event: {$eventType} reported by {$provider}",
                    );
                }
            }
        } elseif ($eventType === 'delivered' && $recipient !== null && $recipient->status === RecipientStatus::Pending) {
            $recipient->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
        }

        return $event;
    }

    /**
     * Normalize provider-specific webhook payload schemas into unified structure.
     *
     * @param  array<string|int, mixed>  $payload
     * @return array{email: string, event_type: string, tracking_token?: string|null, error_code?: string|null, error_message?: string|null}
     */
    protected function normalizePayload(string $provider, array $payload): array
    {
        return match (strtolower($provider)) {
            'mailgun' => [
                'email' => (string) ($payload['event-data']['recipient'] ?? ($payload['recipient'] ?? '')),
                'event_type' => (string) ($payload['event-data']['event'] ?? ($payload['event'] ?? 'unknown')),
                'error_code' => (string) ($payload['event-data']['delivery-status']['code'] ?? null),
                'error_message' => (string) ($payload['event-data']['delivery-status']['message'] ?? null),
                'tracking_token' => (string) ($payload['event-data']['user-variables']['focal_token'] ?? null),
            ],
            'ses' => [
                'email' => (string) ($payload['mail']['destination'][0] ?? ($payload['email'] ?? '')),
                'event_type' => strtolower((string) ($payload['eventType'] ?? ($payload['event_type'] ?? 'bounce'))),
                'error_code' => (string) ($payload['bounce']['bounceSubType'] ?? null),
                'error_message' => (string) ($payload['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? null),
                'tracking_token' => (string) ($payload['mail']['headersTruncated']['X-Focal-Token'] ?? null),
            ],
            'postmark' => [
                'email' => (string) ($payload['Recipient'] ?? ($payload['Email'] ?? '')),
                'event_type' => strtolower((string) ($payload['RecordType'] ?? 'bounce')),
                'error_code' => (string) ($payload['TypeCode'] ?? null),
                'error_message' => (string) ($payload['Details'] ?? null),
                'tracking_token' => (string) ($payload['Metadata']['focal_token'] ?? null),
            ],
            'sendgrid' => [
                'email' => (string) ($payload['email'] ?? ''),
                'event_type' => match (strtolower((string) ($payload['event'] ?? ''))) {
                    'bounce', 'dropped' => 'bounce',
                    'spamreport' => 'complaint',
                    'unsubscribe' => 'unsubscribed',
                    default => (string) ($payload['event'] ?? 'unknown'),
                },
                'error_code' => (string) ($payload['status'] ?? null),
                'error_message' => (string) ($payload['reason'] ?? null),
                'tracking_token' => (string) ($payload['focal_token'] ?? null),
            ],
            'resend' => [
                'email' => (string) (($payload['data']['to'][0] ?? null) ?? ($payload['email'] ?? '')),
                'event_type' => match (strtolower((string) ($payload['type'] ?? ''))) {
                    'email.bounced' => 'bounce',
                    'email.complained' => 'complaint',
                    'email.delivered' => 'delivered',
                    default => (string) ($payload['type'] ?? 'unknown'),
                },
                'error_code' => (string) ($payload['data']['bounce_type'] ?? null),
                'error_message' => (string) ($payload['data']['message'] ?? null),
                'tracking_token' => (string) ($payload['data']['tags']['focal_token'] ?? null),
            ],
            default => [
                'email' => (string) ($payload['email'] ?? ($payload['recipient'] ?? '')),
                'event_type' => (string) ($payload['event_type'] ?? ($payload['type'] ?? ($payload['event'] ?? 'bounce'))),
                'error_code' => (string) ($payload['error_code'] ?? ($payload['code'] ?? null)),
                'error_message' => (string) ($payload['error_message'] ?? ($payload['reason'] ?? null)),
                'tracking_token' => (string) ($payload['tracking_token'] ?? null),
            ],
        };
    }
}
