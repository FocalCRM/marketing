<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property int $template_id
 * @property int $version_number
 * @property string $name
 * @property string $subject
 * @property string|null $subject_variant_b
 * @property string|null $preview_text
 * @property string|null $preview_text_variant_b
 * @property list<array<string, mixed>>|null $slots
 * @property list<array<string, mixed>>|null $slots_variant_b
 * @property array<string, mixed>|null $theme
 * @property string $body_html
 * @property string|null $body_text
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingTemplate $template
 * @property-read Model|null $creator
 */
class MarketingTemplateRevision extends Model
{
    protected $table = 'odden_marketing_template_revisions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'template_id',
        'version_number',
        'name',
        'subject',
        'subject_variant_b',
        'preview_text',
        'preview_text_variant_b',
        'slots',
        'slots_variant_b',
        'theme',
        'body_html',
        'body_text',
        'notes',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'slots' => 'array',
        'slots_variant_b' => 'array',
        'theme' => 'array',
        'version_number' => 'integer',
    ];

    /**
     * @return BelongsTo<MarketingTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::className(), 'created_by');
    }
}
