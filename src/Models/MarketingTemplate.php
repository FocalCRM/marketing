<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use DoPHP\MailBuilder\MailBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string $subject
 * @property string|null $subject_variant_b
 * @property string|null $preview_text
 * @property string|null $preview_text_variant_b
 * @property list<array<string, mixed>>|null $slots
 * @property list<array<string, mixed>>|null $slots_variant_b
 * @property array<string, mixed>|null $theme
 * @property int $ab_split_percentage
 * @property string|null $ab_winner_variant
 * @property CarbonInterface|null $ab_completed_at
 * @property string $body_html
 * @property string|null $body_html_variant_b
 * @property string|null $body_text
 * @property string|null $body_text_variant_b
 * @property string $category
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, Campaign> $campaigns
 * @property-read Collection<int, MarketingTemplateRevision> $revisions
 */
class MarketingTemplate extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'subject',
        'subject_variant_b',
        'preview_text',
        'preview_text_variant_b',
        'slots',
        'slots_variant_b',
        'theme',
        'ab_split_percentage',
        'ab_winner_variant',
        'ab_completed_at',
        'body_html',
        'body_html_variant_b',
        'body_text',
        'body_text_variant_b',
        'category',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'slots' => 'array',
        'slots_variant_b' => 'array',
        'theme' => 'array',
        'ab_split_percentage' => 'integer',
        'ab_completed_at' => 'datetime',
    ];

    /**
     * Determine if this template has an active A/B test configured.
     */
    public function hasAbTest(): bool
    {
        return ! empty($this->subject_variant_b) || ! empty($this->slots_variant_b);
    }

    /**
     * Get the HTML for a given variant (A or B).
     */
    public function getVariantHtml(?string $variant = 'A'): string
    {
        $v = strtoupper($variant ?? 'A');
        if ($v === 'B' && ! empty($this->body_html_variant_b)) {
            return $this->body_html_variant_b;
        }

        return $this->body_html ?? '';
    }

    /**
     * Get the Subject for a given variant (A or B).
     */
    public function getVariantSubject(?string $variant = 'A'): string
    {
        $v = strtoupper($variant ?? 'A');
        if ($v === 'B' && ! empty($this->subject_variant_b)) {
            return $this->subject_variant_b;
        }

        return $this->subject;
    }

    /**
     * Get the Plain Text for a given variant (A or B).
     */
    public function getVariantText(?string $variant = 'A'): ?string
    {
        $v = strtoupper($variant ?? 'A');
        if ($v === 'B' && ! empty($this->body_text_variant_b)) {
            return $this->body_text_variant_b;
        }

        return $this->body_text;
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (MarketingTemplate $template): void {
            if (! empty($template->slots)) {
                if (class_exists(MailBuilder::class)) {
                    $template->body_html = MailBuilder::compile($template->slots, [
                        'subject' => $template->subject,
                        'preview_text' => $template->preview_text,
                        'theme' => $template->theme ?? [],
                    ]);
                    if (blank($template->body_text)) {
                        $template->body_text = MailBuilder::plainText($template->slots);
                    }
                }
            } elseif (! empty($template->body_html) && blank($template->body_text)) {
                if (class_exists(MailBuilder::class)) {
                    $template->body_text = MailBuilder::plainText($template->body_html);
                }
            }

            // Compile Variant B if configured
            if (! empty($template->slots_variant_b)) {
                if (class_exists(MailBuilder::class)) {
                    $template->body_html_variant_b = MailBuilder::compile($template->slots_variant_b, [
                        'subject' => $template->subject_variant_b ?? $template->subject,
                        'preview_text' => $template->preview_text_variant_b ?? $template->preview_text,
                        'theme' => $template->theme ?? [],
                    ]);
                    if (blank($template->body_text_variant_b)) {
                        $template->body_text_variant_b = MailBuilder::plainText($template->slots_variant_b);
                    }
                }
            } elseif (! empty($template->subject_variant_b) && ! empty($template->slots)) {
                // If variant B only changes the subject line, reuse slot A HTML
                if (class_exists(MailBuilder::class)) {
                    $template->body_html_variant_b = MailBuilder::compile($template->slots, [
                        'subject' => $template->subject_variant_b,
                        'preview_text' => $template->preview_text_variant_b ?? $template->preview_text,
                        'theme' => $template->theme ?? [],
                    ]);
                }
            }

            if (empty($template->slug) && ! empty($template->name)) {
                $template->slug = Str::slug($template->name);
            }
        });

        static::saved(function (MarketingTemplate $template): void {
            // Snapshot revision automatically on save if slots or html exist
            if (! empty($template->slots) || ! empty($template->body_html)) {
                $template->createRevision();
            }
        });
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.templates', 'focal_marketing_templates');
    }

    /**
     * Campaigns using this template.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }

    /**
     * Revision history of this template.
     *
     * @return HasMany<MarketingTemplateRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(MarketingTemplateRevision::class, 'template_id')->orderByDesc('version_number');
    }

    /**
     * Create a snapshot revision of the current template state.
     */
    public function createRevision(?string $notes = null, ?int $userId = null): MarketingTemplateRevision
    {
        $latestVersion = (int) ($this->revisions()->max('version_number') ?? 0);

        return $this->revisions()->create([
            'version_number' => $latestVersion + 1,
            'name' => $this->name,
            'subject' => $this->subject,
            'subject_variant_b' => $this->subject_variant_b,
            'preview_text' => $this->preview_text,
            'preview_text_variant_b' => $this->preview_text_variant_b,
            'slots' => $this->slots,
            'slots_variant_b' => $this->slots_variant_b,
            'theme' => $this->theme,
            'body_html' => $this->body_html ?? '',
            'body_text' => $this->body_text,
            'notes' => $notes,
            'created_by' => $userId ?? (auth()->check() ? (int) auth()->id() : null),
        ]);
    }

    /**
     * Restore this template to a previous revision.
     */
    public function restoreRevision(int|MarketingTemplateRevision $revision): bool
    {
        $rev = is_int($revision) ? $this->revisions()->findOrFail($revision) : $revision;

        return $this->update([
            'subject' => $rev->subject,
            'subject_variant_b' => $rev->subject_variant_b,
            'preview_text' => $rev->preview_text,
            'preview_text_variant_b' => $rev->preview_text_variant_b,
            'slots' => $rev->slots,
            'slots_variant_b' => $rev->slots_variant_b,
            'theme' => $rev->theme,
            'body_html' => $rev->body_html,
            'body_text' => $rev->body_text,
        ]);
    }

    /**
     * @return HasMany<MarketingTemplateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MarketingTemplateTranslation::class, 'template_id');
    }

    /**
     * Get subject line for given variant and optional locale.
     */
    public function getLocalizedSubject(?string $locale = null, ?string $variant = 'A'): string
    {
        if (! empty($locale)) {
            $trans = $this->translations()->where('locale', $locale)->first();
            if ($trans !== null) {
                if (strtoupper($variant ?? 'A') === 'B' && ! empty($trans->subject_variant_b)) {
                    return $trans->subject_variant_b;
                }

                return $trans->subject;
            }
        }

        return $this->getVariantSubject($variant);
    }
}
