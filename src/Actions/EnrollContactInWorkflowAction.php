<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\WorkflowEnrollmentStatus;
use Focal\Marketing\Enums\WorkflowTriggerType;
use Focal\Marketing\Models\MarketingForm;
use Focal\Marketing\Models\MarketingWorkflow;
use Focal\Marketing\Models\WorkflowEnrollment;

class EnrollContactInWorkflowAction
{
    /**
     * Enroll a contact into a specific marketing workflow.
     */
    public function execute(MarketingWorkflow $workflow, Contact $contact): ?WorkflowEnrollment
    {
        if (! $workflow->is_active) {
            return null;
        }

        // Avoid concurrent active enrollment in the same workflow
        $existing = WorkflowEnrollment::query()
            ->where('workflow_id', $workflow->id)
            ->where('contact_id', $contact->id)
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $firstStep = $workflow->steps()->orderBy('step_number', 'asc')->first();
        if ($firstStep === null) {
            return null;
        }

        /** @var WorkflowEnrollment $enrollment */
        $enrollment = WorkflowEnrollment::create([
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'current_step_id' => $firstStep->id,
            'status' => WorkflowEnrollmentStatus::Active,
            'next_run_at' => now(),
            'enrolled_at' => now(),
        ]);

        $workflow->increment('enrollments_count');

        // Immediately execute step 1 if no initial delay
        app(ExecuteWorkflowStepAction::class)->execute($enrollment);

        return $enrollment->fresh();
    }

    /**
     * Trigger workflows listening for form submission events.
     */
    public function triggerFormWorkflows(MarketingForm $form, Contact $contact): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::FormSubmitted->value)
            ->get();

        foreach ($workflows as $workflow) {
            $configuredFormId = $workflow->trigger_config['form_id'] ?? null;
            if ($configuredFormId === null || (int) $configuredFormId === $form->id) {
                $this->execute($workflow, $contact);
            }
        }
    }

    /**
     * Trigger workflows listening for custom in-app behavioral events.
     */
    public function triggerCustomEventWorkflows(string $eventName, Contact $contact): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::CustomEvent->value)
            ->get();

        foreach ($workflows as $workflow) {
            $configuredEvent = $workflow->trigger_config['event_name'] ?? null;
            if ($configuredEvent === null || strtolower((string) $configuredEvent) === strtolower($eventName)) {
                $this->execute($workflow, $contact);
            }
        }
    }
}
