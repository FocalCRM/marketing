<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $asset_type
 * @property string|null $file_path
 * @property string|null $external_url
 * @property int|null $file_size_kb
 * @property bool $is_gated
 * @property int $lead_score_points
 * @property int $downloads_count
 * @property int $unique_leads_count
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, MarketingAssetDownload> $downloads
 * @property-read Collection<int, Contact> $downloadingContacts
 */
class MarketingAsset extends Model
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
        'asset_type',
        'file_path',
        'external_url',
        'file_size_kb',
        'is_gated',
        'lead_score_points',
        'downloads_count',
        'unique_leads_count',
        'is_active',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.assets', 'focal_marketing_assets');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_gated' => 'boolean',
            'is_active' => 'boolean',
            'lead_score_points' => 'integer',
            'downloads_count' => 'integer',
            'unique_leads_count' => 'integer',
            'file_size_kb' => 'integer',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            if (empty($asset->slug)) {
                $asset->slug = Str::slug($asset->name);
            }
        });
    }

    /**
     * Tracked download events.
     *
     * @return HasMany<MarketingAssetDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(MarketingAssetDownload::class, 'asset_id');
    }

    /**
     * Contacts that have downloaded this asset.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function downloadingContacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            config('focal-marketing.tables.asset_downloads', 'focal_marketing_asset_downloads'),
            'asset_id',
            'contact_id'
        )->withTimestamps()->withPivot(['downloaded_at', 'ip_address']);
    }

    /**
     * Generate secure tokenized download URL for email or landing page redirection.
     */
    public function getDownloadUrl(?Contact $contact = null): string
    {
        $params = [];
        if ($contact !== null) {
            $params['contact_id'] = $contact->id;
            $params['signature'] = hash_hmac('sha256', "asset_{$this->id}_contact_{$contact->id}", config('app.key'));
        }

        return route('focal.marketing.assets.download', array_merge(['slug' => $this->slug], $params));
    }
}
