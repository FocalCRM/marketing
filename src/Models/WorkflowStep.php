<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Marketing\Enums\WorkflowStepType;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int $step_number
 * @property WorkflowStepType $type
 * @property array<string, mixed> $config
 * @property int|null $next_step_on_true
 * @property int|null $next_step_on_false
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingWorkflow $workflow
 */
class WorkflowStep extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'step_number',
        'type',
        'config',
        'next_step_on_true',
        'next_step_on_false',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.workflow_steps', 'odden_marketing_workflow_steps');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkflowStepType::class,
            'config' => 'array',
            'step_number' => 'integer',
            'next_step_on_true' => 'integer',
            'next_step_on_false' => 'integer',
        ];
    }

    /**
     * Owning workflow.
     *
     * @return BelongsTo<MarketingWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(MarketingWorkflow::class, 'workflow_id');
    }
}
