<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contact_id
 * @property int|null $rule_id
 * @property string $event_type
 * @property string $event_description
 * @property int $score_change
 * @property int $score_after
 * @property CarbonInterface|null $created_at
 * @property-read Contact $contact
 * @property-read LeadScoringRule|null $rule
 */
class LeadScoreLog extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'rule_id',
        'event_type',
        'event_description',
        'score_change',
        'score_after',
        'created_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.score_logs', 'focal_marketing_lead_score_logs');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_change' => 'integer',
            'score_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Scored contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Triggering rule.
     *
     * @return BelongsTo<LeadScoringRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(LeadScoringRule::class, 'rule_id');
    }
}
