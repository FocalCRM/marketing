<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Models\Contact;

/**
 * @property int $id
 * @property int|null $contact_id
 * @property int|null $campaign_id
 * @property string $phone_number
 * @property string $message_body
 * @property string $status
 * @property string|null $provider_message_id
 * @property string|null $error_message
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Contact|null $contact
 * @property-read Campaign|null $campaign
 */
class MarketingSmsMessage extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'campaign_id',
        'phone_number',
        'message_body',
        'status',
        'provider_message_id',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.sms_messages', 'odden_marketing_sms_messages');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Recipient contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Associated campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
