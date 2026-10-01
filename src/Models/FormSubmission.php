<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $form_id
 * @property int|null $contact_id
 * @property array<string, mixed> $form_data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingForm $form
 * @property-read Contact|null $contact
 */
class FormSubmission extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'contact_id',
        'form_data',
        'ip_address',
        'user_agent',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.form_submissions', 'focal_marketing_form_submissions');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form_data' => 'array',
        ];
    }

    /**
     * Associated marketing form.
     *
     * @return BelongsTo<MarketingForm, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(MarketingForm::class, 'form_id');
    }

    /**
     * Associated contact record.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
