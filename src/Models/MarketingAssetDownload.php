<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Odden\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $asset_id
 * @property int|null $contact_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $download_token
 * @property CarbonInterface $downloaded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingAsset $asset
 * @property-read Contact|null $contact
 */
class MarketingAssetDownload extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_id',
        'contact_id',
        'ip_address',
        'user_agent',
        'download_token',
        'downloaded_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.asset_downloads', 'odden_marketing_asset_downloads');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * Associated digital asset.
     *
     * @return BelongsTo<MarketingAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }

    /**
     * Downloading contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
