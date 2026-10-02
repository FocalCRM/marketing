<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Models\Contact;

/**
 * @property int $id
 * @property string $visitor_token
 * @property int|null $contact_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referer
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Contact|null $contact
 * @property-read Collection<int, PageView> $pageViews
 */
class VisitorSession extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'visitor_token',
        'contact_id',
        'ip_address',
        'user_agent',
        'referer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.visitor_sessions', 'odden_marketing_visitor_sessions');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The associated contact if stitched.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Page views captured in this session.
     *
     * @return HasMany<PageView, $this>
     */
    public function pageViews(): HasMany
    {
        return $this->hasMany(PageView::class, 'session_id');
    }
}
