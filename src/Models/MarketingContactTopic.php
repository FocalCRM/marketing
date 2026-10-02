<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Models\Contact;

/**
 * @property int $id
 * @property string $email
 * @property int|null $contact_id
 * @property int $topic_id
 * @property bool $is_subscribed
 * @property CarbonInterface|null $unsubscribed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingSubscriptionTopic $topic
 * @property-read Contact|null $contact
 */
class MarketingContactTopic extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'contact_id',
        'topic_id',
        'is_subscribed',
        'unsubscribed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_subscribed' => 'boolean',
        'unsubscribed_at' => 'datetime',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.contact_topics', 'odden_marketing_contact_topics');
    }

    /**
     * Topic definition.
     *
     * @return BelongsTo<MarketingSubscriptionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(MarketingSubscriptionTopic::class, 'topic_id');
    }

    /**
     * Associated CRM contact (if linked).
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
