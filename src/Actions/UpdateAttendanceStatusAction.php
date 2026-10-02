<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;
use Odden\Marketing\Models\MarketingWorkflow;

class UpdateAttendanceStatusAction
{
    public function __construct(
        public ApplyLeadScoringEventAction $scoringAction,
        public EnrollContactInWorkflowAction $enrollmentAction,
    ) {}

    /**
     * Update an event attendee's status (attended, no_show, cancelled), update event counts,
     * award attendee lead scoring, and trigger post-event workflows.
     */
    public function execute(
        MarketingEventRegistration $registration,
        string $status
    ): MarketingEventRegistration {
        return DB::transaction(function () use ($registration, $status): MarketingEventRegistration {
            $isBecomingAttended = $status === 'attended' && $registration->status !== 'attended';

            $registration->update([
                'status' => $status,
                'attended_at' => $isBecomingAttended ? now() : $registration->attended_at,
            ]);

            /** @var MarketingEvent $event */
            $event = $registration->event;

            // Recalculate attendees_count
            $attendeesCount = MarketingEventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', 'attended')
                ->count();

            $event->updateQuietly(['attendees_count' => $attendeesCount]);

            /** @var Contact $contact */
            $contact = $registration->contact;

            if ($isBecomingAttended) {
                // Log attendance on timeline
                $contact->logTask(
                    title: "Attended Event: {$event->title}",
                    dueAt: now(),
                    body: "Attended {$event->event_type} on ".now()->toFormattedDateString().'.'
                );

                // Award live attendance bonus (+20 pts)
                $this->scoringAction->execute(
                    contact: $contact,
                    eventType: LeadScoringEventType::PropertyMatch,
                    description: "Attended Event: {$event->title} (+20 pts)",
                    points: 20,
                );

                // Trigger active workflows listening for event attendance
                /** @var Collection<int, MarketingWorkflow> $workflows */
                $workflows = MarketingWorkflow::query()
                    ->where('is_active', true)
                    ->where('trigger_type', WorkflowTriggerType::EventAttended)
                    ->get();

                foreach ($workflows as $workflow) {
                    $targetEventId = $workflow->trigger_config['event_id'] ?? null;
                    if ($targetEventId === null || (int) $targetEventId === $event->id) {
                        $this->enrollmentAction->execute($workflow, $contact);
                    }
                }
            }

            return $registration->fresh() ?? $registration;
        });
    }
}
