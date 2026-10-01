<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $session_id
 * @property int|null $contact_id
 * @property string $url
 * @property string $path
 * @property string|null $title
 * @property int|null $duration_seconds
 * @property CarbonInterface $created_at
 * @property-read VisitorSession $session
 * @property-read Contact|null $contact
 */
class PageView extends Model
{
    /**
     * Disable updated_at timestamp since page views are immutable event logs.
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
        'session_id',
        'contact_id',
        'url',
        'path',
        'title',
        'duration_seconds',
        'created_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.page_views', 'focal_marketing_page_views');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The parent visitor session.
     *
     * @return BelongsTo<VisitorSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(VisitorSession::class, 'session_id');
    }

    /**
     * The associated contact if identified.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
