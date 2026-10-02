<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $headline
 * @property string|null $subheadline
 * @property string|null $body_content
 * @property int|null $form_id
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $og_image_url
 * @property bool $is_published
 * @property int $views_count
 * @property int $submissions_count
 * @property CarbonInterface|null $published_at
 * @property int|null $created_by_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingForm|null $form
 * @property-read Model|null $creator
 * @property-read float $conversion_rate
 */
class LandingPage extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'headline',
        'subheadline',
        'body_content',
        'form_id',
        'meta_title',
        'meta_description',
        'og_image_url',
        'is_published',
        'views_count',
        'submissions_count',
        'published_at',
        'created_by_id',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_published' => false,
        'views_count' => 0,
        'submissions_count' => 0,
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.landing_pages', 'odden_marketing_landing_pages');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'views_count' => 'integer',
            'submissions_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Embedded lead capture form.
     *
     * @return BelongsTo<MarketingForm, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(MarketingForm::class, 'form_id');
    }

    /**
     * Author / user who created the landing page.
     *
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'created_by_id');
    }

    /**
     * Get conversion rate (submissions / views * 100).
     */
    public function getConversionRateAttribute(): float
    {
        if ($this->views_count === 0) {
            return 0.0;
        }

        return round(($this->submissions_count / $this->views_count) * 100, 2);
    }

    /**
     * Get the public URL for this landing page.
     */
    public function getPublicUrl(): string
    {
        return route('odden.marketing.landing-pages.show', $this->slug);
    }

    /**
     * Generate HTML iframe embed snippet.
     */
    public function getEmbedSnippet(): string
    {
        $url = htmlspecialchars($this->getPublicUrl(), ENT_QUOTES, 'UTF-8');

        return "<iframe src=\"{$url}\" width=\"100%\" height=\"600\" frameborder=\"0\" style=\"border:none;\"></iframe>";
    }
}
