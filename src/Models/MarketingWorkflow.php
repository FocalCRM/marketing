<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Support\UserModel;
use Odden\Marketing\Enums\WorkflowTriggerType;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property WorkflowTriggerType $trigger_type
 * @property array<string, mixed>|null $trigger_config
 * @property bool $is_active
 * @property int $enrollments_count
 * @property int $completed_count
 * @property int|null $created_by_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, WorkflowStep> $steps
 * @property-read Collection<int, WorkflowEnrollment> $enrollments
 * @property-read Model|null $creator
 */
class MarketingWorkflow extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'is_active',
        'enrollments_count',
        'completed_count',
        'created_by_id',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'enrollments_count' => 0,
        'completed_count' => 0,
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.workflows', 'odden_marketing_workflows');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => WorkflowTriggerType::class,
            'trigger_config' => 'array',
            'is_active' => 'boolean',
            'enrollments_count' => 'integer',
            'completed_count' => 'integer',
        ];
    }

    /**
     * Ordered sequence of steps.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'workflow_id')->orderBy('step_number', 'asc');
    }

    /**
     * Enrolled contacts.
     *
     * @return HasMany<WorkflowEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(WorkflowEnrollment::class, 'workflow_id');
    }

    /**
     * User who created the workflow.
     *
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'created_by_id');
    }
}
