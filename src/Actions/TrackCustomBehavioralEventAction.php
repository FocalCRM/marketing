<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Carbon\CarbonInterface;
use Focal\Core\Enums\LeadStatus;
use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\LeadScoringEventType;
use Focal\Marketing\Models\CustomBehavioralEvent;

class TrackCustomBehavioralEventAction
{
    /**
     * Ingest and record a custom behavioral event from in-app product actions,
     * enrich contact timeline, apply lead score increment, and trigger workflows.
     *
     * @param  array<string, mixed>  $properties
     */
    public function execute(
        string $eventName,
        ?Contact $contact = null,
        ?string $email = null,
        array $properties = [],
        ?CarbonInterface $occurredAt = null
    ): CustomBehavioralEvent {
        if ($contact === null && ! empty($email)) {
            $normalizedEmail = strtolower(trim($email));
            /** @var Contact|null $contact */
            $contact = Contact::query()->where('email', $normalizedEmail)->first();

            if ($contact === null) {
                $contact = Contact::create([
                    'email' => $normalizedEmail,
                    'lead_status' => LeadStatus::New,
                    'lifecycle_stage' => LifecycleStage::Lead,
                ]);

                // Auto-match to corporate domain account
                app(AutoMatchLeadToCompanyAction::class)->execute($contact);
            }
        }

        /** @var Company|null $company */
        $company = $contact !== null ? $contact->companies()->first() : null;

        /** @var CustomBehavioralEvent $event */
        $event = CustomBehavioralEvent::create([
            'contact_id' => $contact?->id,
            'company_id' => $company?->id,
            'event_name' => $eventName,
            'properties' => $properties,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        if ($contact !== null) {
            // 1. Log activity on Contact timeline
            $details = ! empty($properties) ? (string) json_encode($properties) : 'No extra metadata';
            $contact->logTask(
                title: "Custom Event: {$eventName}",
                dueAt: now(),
                body: "Recorded in-app behavioral event [{$eventName}]. Properties: {$details}"
            );

            // 2. Lead scoring adjustment (+10 pts)
            app(ApplyLeadScoringEventAction::class)->execute(
                contact: $contact,
                eventType: LeadScoringEventType::CustomEvent,
                description: "Triggered in-app event: {$eventName}",
            );

            // 3. Trigger workflows listening for this event
            app(EnrollContactInWorkflowAction::class)->triggerCustomEventWorkflows($eventName, $contact);

            // 4. Update company intent if company attached
            if ($company !== null) {
                app(CalculateCompanyIntentScoreAction::class)->execute($company);
            }
        }

        return $event;
    }
}
