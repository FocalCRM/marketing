<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property array<int, array<string, mixed>> $fields_schema
 * @property string $submit_button_text
 * @property string|null $success_message
 * @property string|null $redirect_url
 * @property bool $is_active
 * @property int $submissions_count
 * @property bool $progressive_profiling_enabled
 * @property array<int, array<string, mixed>>|null $progressive_fields
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, FormSubmission> $submissions
 */
class MarketingForm extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'description',
        'fields_schema',
        'progressive_profiling_enabled',
        'progressive_fields',
        'submit_button_text',
        'success_message',
        'redirect_url',
        'is_active',
        'submissions_count',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.forms', 'focal_marketing_forms');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields_schema' => 'array',
            'progressive_profiling_enabled' => 'boolean',
            'progressive_fields' => 'array',
            'is_active' => 'boolean',
            'submissions_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $form): void {
            if (empty($form->slug)) {
                $form->slug = Str::slug($form->title);
            }
        });
    }

    /**
     * Form submissions.
     *
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the public hosted URL for this form.
     */
    public function getPublicUrl(): string
    {
        return route('focal.marketing.forms.show', $this->slug);
    }

    /**
     * Get the public API submission endpoint.
     */
    public function getApiEndpoint(): string
    {
        return route('focal.marketing.forms.api-submit', $this->slug);
    }

    /**
     * Determine the schema of fields to render for a given visitor/contact.
     * If progressive profiling is enabled and the contact is recognized, known fields
     * are substituted with uncollected progressive qualification fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveFieldsForContact(?Contact $contact): array
    {
        /** @var array<int, array<string, mixed>> $baseFields */
        $baseFields = $this->fields_schema ?? [];

        if (! $this->progressive_profiling_enabled || $contact === null || empty($this->progressive_fields)) {
            return $baseFields;
        }

        /** @var array<int, array<string, mixed>> $progressiveQueue */
        $progressiveQueue = $this->progressive_fields;
        $resolvedFields = [];

        foreach ($baseFields as $field) {
            $fieldName = (string) ($field['name'] ?? '');
            $isKnown = $this->isFieldKnownByContact($contact, $fieldName);

            if ($isKnown && ! empty($progressiveQueue)) {
                $nextProgressiveField = array_shift($progressiveQueue);
                $nextProgressiveField['is_progressive'] = true;
                $resolvedFields[] = $nextProgressiveField;
            } elseif (! $isKnown) {
                $resolvedFields[] = $field;
            }
        }

        if (empty($resolvedFields) && ! empty($this->progressive_fields)) {
            $resolvedFields = array_slice($this->progressive_fields, 0, 3);
        }

        return $resolvedFields;
    }

    /**
     * Check if a contact already has a value for a specific field name.
     */
    public function isFieldKnownByContact(Contact $contact, string $fieldName): bool
    {
        return match ($fieldName) {
            'email' => ! empty($contact->email),
            'first_name' => ! empty($contact->first_name),
            'last_name' => ! empty($contact->last_name),
            'phone' => ! empty($contact->phone),
            'company' => $contact->companies()->exists(),
            default => ! empty($contact->getProperty($fieldName)),
        };
    }
}
