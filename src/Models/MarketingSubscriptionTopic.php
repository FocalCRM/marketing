<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_default
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, Campaign> $campaigns
 * @property-read Collection<int, MarketingContactTopic> $contactTopics
 */
class MarketingSubscriptionTopic extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.subscription_topics', 'odden_marketing_subscription_topics');
    }

    /**
     * Campaigns mapped to this communication topic.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'topic_id');
    }

    /**
     * Contact topic preferences.
     *
     * @return HasMany<MarketingContactTopic, $this>
     */
    public function contactTopics(): HasMany
    {
        return $this->hasMany(MarketingContactTopic::class, 'topic_id');
    }

    /**
     * Check if a contact/email is subscribed to this topic.
     */
    public static function isSubscribed(string $email, int|string $topicIdOrSlug): bool
    {
        $email = mb_strtolower(trim($email));

        /** @var MarketingSubscriptionTopic|null $topic */
        $topic = is_numeric($topicIdOrSlug)
            ? self::find((int) $topicIdOrSlug)
            : self::where('slug', $topicIdOrSlug)->first();

        if ($topic === null) {
            return true;
        }

        /** @var MarketingContactTopic|null $record */
        $record = MarketingContactTopic::query()
            ->where('email', $email)
            ->where('topic_id', $topic->id)
            ->first();

        if ($record !== null) {
            return (bool) $record->is_subscribed;
        }

        return $topic->is_default;
    }

    /**
     * Set subscription status for a specific contact and topic.
     */
    public static function setSubscription(string $email, int $topicId, bool $isSubscribed, ?int $contactId = null): MarketingContactTopic
    {
        $email = mb_strtolower(trim($email));

        /** @var MarketingContactTopic $record */
        $record = MarketingContactTopic::updateOrCreate(
            ['email' => $email, 'topic_id' => $topicId],
            [
                'contact_id' => $contactId,
                'is_subscribed' => $isSubscribed,
                'unsubscribed_at' => $isSubscribed ? null : now(),
            ]
        );

        return $record;
    }
}
