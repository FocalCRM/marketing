<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\WorkflowEnrollmentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return config('focal-marketing.tables.workflow_enrollments', 'focal_marketing_workflow_enrollments');
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
     * Execution step logs.
     *
     * @return HasMany<WorkflowLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'enrollment_id');
    }
}
