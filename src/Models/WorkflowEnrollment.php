<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int $contact_id
 * @property int|null $current_step_id
 * @property WorkflowEnrollmentStatus $status
 * @property CarbonInterface|null $next_run_at
 * @property CarbonInterface|null $enrolled_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingWorkflow $workflow
 * @property-read Contact $contact
 * @property-read WorkflowStep|null $currentStep
 * @property-read Collection<int, WorkflowLog> $logs
 */
class WorkflowEnrollment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'contact_id',
        'current_step_id',
        'status',
        'next_run_at',
        'enrolled_at',
        'completed_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.workflow_enrollments', 'odden_marketing_workflow_enrollments');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowEnrollmentStatus::class,
            'next_run_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Workflow enrolled in.
     *
     * @return BelongsTo<MarketingWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(MarketingWorkflow::class, 'workflow_id');
    }

    /**
     * Contact enrolled.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Current step pointer.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    /**
     * Atomically claim the step this enrollment is due to run, before running its side effects.
     *
     * One conditional UPDATE: it only matches while the enrollment is still active, still on
     * $stepId, and still due (next_run_at set), and it clears next_run_at. Whoever runs it first
     * wins; a second worker holding a stale copy (an overlapping scheduler run, or the scheduler
     * racing the inline run after enrolment) gets false and must do nothing. The step then sets
     * next_run_at again when it moves the enrollment on.
     */
    public function claimStep(int $stepId): bool
    {
        $attributes = ['next_run_at' => null];
        $updatedAt = $this->getUpdatedAtColumn();
        if ($updatedAt !== null) {
            $attributes[$updatedAt] = $this->freshTimestampString();
        }

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->where('current_step_id', $stepId)
            ->whereNotNull('next_run_at')
            ->update($attributes) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    /**
     * Atomically mark this enrollment completed. False when it was no longer active.
     */
    public function claimCompletion(): bool
    {
        $attributes = [
            'status' => WorkflowEnrollmentStatus::Completed->value,
            'current_step_id' => null,
            'next_run_at' => null,
            'completed_at' => $this->freshTimestampString(),
        ];
        $updatedAt = $this->getUpdatedAtColumn();
        if ($updatedAt !== null) {
            $attributes[$updatedAt] = $this->freshTimestampString();
        }

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->update($attributes) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * Execution step logs.
     *
     * @return HasMany<WorkflowLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'enrollment_id');
    }
}
