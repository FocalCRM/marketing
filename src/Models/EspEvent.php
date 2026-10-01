<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $provider
 * @property string $event_type
 * @property string $email
 * @property int|null $campaign_id
 * @property int|null $recipient_id
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $created_at
 * @property-read Campaign|null $campaign
 * @property-read CampaignRecipient|null $recipient
 */
class EspEvent extends Model
{
    /**
     * Disable updated_at since ESP events are append-only.
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
        'provider',
        'event_type',
        'email',
        'campaign_id',
        'recipient_id',
        'error_code',
        'error_message',
        'payload',
        'created_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.esp_events', 'focal_marketing_esp_events');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
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

    /**
     * Associated campaign recipient.
     *
     * @return BelongsTo<CampaignRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }
}
