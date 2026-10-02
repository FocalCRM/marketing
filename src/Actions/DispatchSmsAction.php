<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingSmsMessage;

class DispatchSmsAction
{
    /**
     * Dispatch an SMS to a contact, recording the message and updating activity log.
     */
    public function execute(
        Contact $contact,
        string $message,
        ?Campaign $campaign = null,
        bool $requiresConsent = true,
        ?string $title = null
    ): MarketingSmsMessage {
        $compiler = app(CompileCampaignMessageAction::class);
        $compiledMessage = $compiler->compileForContact($message, $contact, escape: false);

        $hasPhone = ! empty($contact->phone);
        $hasConsent = ! $requiresConsent || (bool) $contact->sms_consent;

        if (! $hasPhone) {
            return MarketingSmsMessage::create([
                'contact_id' => $contact->id,
                'campaign_id' => $campaign?->id,
                'phone_number' => '',
                'message_body' => $compiledMessage,
                'status' => 'failed',
                'error_message' => 'Contact missing phone number',
            ]);
        }

        if (! $hasConsent) {
            return MarketingSmsMessage::create([
                'contact_id' => $contact->id,
                'campaign_id' => $campaign?->id,
                'phone_number' => (string) $contact->phone,
                'message_body' => $compiledMessage,
                'status' => 'skipped',
                'error_message' => 'No SMS consent',
            ]);
        }

        $providerId = 'sms_'.Str::random(16);

        /** @var MarketingSmsMessage $sms */
        $sms = MarketingSmsMessage::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign?->id,
            'phone_number' => (string) $contact->phone,
            'message_body' => $compiledMessage,
            'status' => 'delivered',
            'provider_message_id' => $providerId,
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        $taskTitle = $title ?? ($campaign !== null ? "Campaign SMS: {$campaign->name}" : 'Marketing SMS Sent');

        $contact->logTask(
            title: $taskTitle,
            dueAt: now(),
            body: "Delivered SMS to {$contact->phone}: \"{$compiledMessage}\""
        );

        return $sms;
    }
}
