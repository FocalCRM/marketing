<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Marketing\Enums\WorkflowEnrollmentStatus;
use Focal\Marketing\Models\WorkflowEnrollment;

class ProcessDueWorkflowsAction
{
    /**
     * Scan and advance all active workflow enrollments whose wait timers have matured.
     */
    public function execute(): int
    {
        $dueEnrollments = WorkflowEnrollment::query()
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with(['workflow', 'contact', 'currentStep'])
            ->get();

        $processed = 0;
        $executor = app(ExecuteWorkflowStepAction::class);

        foreach ($dueEnrollments as $enrollment) {
            $executor->execute($enrollment);
            $processed++;
        }

        return $processed;
    }
}
