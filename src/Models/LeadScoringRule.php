<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Marketing\Enums\LeadScoringEventType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property LeadScoringEventType $event_type
 * @property array<string, mixed>|null $conditions
 * @property int $score_change
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, LeadScoreLog> $logs
 */
class LeadScoringRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'event_type',
        'conditions',
        'score_change',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'score_change' => 5,
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.scoring_rules', 'focal_marketing_lead_scoring_rules');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => LeadScoringEventType::class,
            'conditions' => 'array',
            'score_change' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Execution logs associated with this scoring rule.
     *
     * @return HasMany<LeadScoreLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(LeadScoreLog::class, 'rule_id');
    }
}
