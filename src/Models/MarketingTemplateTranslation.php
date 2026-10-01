<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $template_id
 * @property string $locale
 * @property string $subject
 * @property string|null $subject_variant_b
 * @property string|null $preview_text
 * @property string|null $preview_text_variant_b
 * @property string|null $body_html
 * @property string|null $body_text
 * @property list<array<string, mixed>>|null $slots
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingTemplate $template
 */
class MarketingTemplateTranslation extends Model
{
    protected $table = 'focal_marketing_template_translations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'template_id',
        'locale',
        'subject',
        'subject_variant_b',
        'preview_text',
        'preview_text_variant_b',
        'body_html',
        'body_text',
        'slots',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'slots' => 'array',
    ];

    /**
     * @return BelongsTo<MarketingTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id');
    }
}
