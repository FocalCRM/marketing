<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Models\Contact;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $score_before
 * @property int $score_after
 * @property int $score_decayed
 * @property int $days_inactive
 * @property CarbonInterface $created_at
 * @property-read Contact $contact
 */
class LeadDecayLog extends Model
{
    /**
     * Disable updated_at since decay logs are immutable event logs.
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
        'score_before',
        'score_after',
        'score_decayed',
        'days_inactive',
        'created_at',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'odden_marketing_lead_decay_logs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_before' => 'integer',
            'score_after' => 'integer',
            'score_decayed' => 'integer',
            'days_inactive' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Associated contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
