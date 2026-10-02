<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Odden\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $survey_id
 * @property int|null $contact_id
 * @property int $score
 * @property string $category
 * @property string|null $feedback
 * @property string $token
 * @property CarbonInterface|null $responded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read NpsSurvey $survey
 * @property-read Contact|null $contact
 */
class NpsResponse extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'survey_id',
        'contact_id',
        'score',
        'category',
        'feedback',
        'token',
        'responded_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.nps_responses', 'odden_marketing_nps_responses');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * Associated survey.
     *
     * @return BelongsTo<NpsSurvey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    /**
     * Responding contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Determine category from score.
     */
    public static function categorizeScore(int $score): string
    {
        return match (true) {
            $score >= 9 => 'promoter',
            $score >= 7 => 'passive',
            default => 'detractor',
        };
    }

    /**
     * Generate unique token for email link.
     */
    public static function createForContact(NpsSurvey $survey, ?Contact $contact = null): self
    {
        return static::create([
            'survey_id' => $survey->id,
            'contact_id' => $contact?->id,
            'score' => 0,
            'category' => 'passive',
            'token' => Str::random(48),
        ]);
    }

    /**
     * Get 1-click rating URL for embedding in emails.
     */
    public function getRatingUrl(int $score): string
    {
        return route('odden.marketing.nps.rate', ['token' => $this->token, 'score' => $score]);
    }
}
