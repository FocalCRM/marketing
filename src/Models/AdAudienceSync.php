<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\CrmList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $platform
 * @property int $list_id
 * @property string|null $audience_id
 * @property bool $is_active
 * @property int $records_count
 * @property CarbonInterface|null $last_synced_at
 * @property array<string, mixed>|null $config
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read CrmList|null $list
 */
class AdAudienceSync extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'platform',
        'list_id',
        'audience_id',
        'is_active',
        'records_count',
        'last_synced_at',
        'config',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.ad_audience_syncs', 'focal_ad_audience_syncs');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'records_count' => 'integer',
            'last_synced_at' => 'datetime',
            'config' => 'array',
        ];
    }

    /**
     * Target audience CRM list.
     *
     * @return BelongsTo<CrmList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(CrmList::class, 'list_id');
    }
}
