<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $contact_id
 * @property string $email
 * @property SubscriptionStatus $status
 * @property CarbonInterface|null $unsubscribed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Contact|null $contact
 */
class MarketingSubscription extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'email',
        'status',
        'unsubscribed_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.subscriptions', 'focal_marketing_subscriptions');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Associated contact record.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Check if an email address is unsubscribed / suppressed globally or for a specific topic.
     */
    public static function isSuppressed(string $email, int|string|null $topicIdOrSlug = null): bool
    {
        $email = mb_strtolower(trim($email));

        $isGloballySuppressed = static::query()
            ->where('email', $email)
            ->whereIn('status', [SubscriptionStatus::Unsubscribed->value, SubscriptionStatus::Bounced->value])
            ->exists();

        if ($isGloballySuppressed || EmailSuppression::isSuppressed($email)) {
            return true;
        }

        if ($topicIdOrSlug !== null) {
            return ! MarketingSubscriptionTopic::isSubscribed($email, $topicIdOrSlug);
        }

        return false;
    }

    /**
     * Mark an email address as unsubscribed.
     */
    public static function unsubscribe(string $email, ?int $contactId = null): self
    {
        return static::updateOrCreate(
            ['email' => mb_strtolower(trim($email))],
            [
                'contact_id' => $contactId,
                'status' => SubscriptionStatus::Unsubscribed,
                'unsubscribed_at' => now(),
            ]
        );
    }
}
