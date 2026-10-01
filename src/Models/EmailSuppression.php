<?php

declare(strict_types=1);

namespace Focal\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property string $reason
 * @property string|null $source
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EmailSuppression extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'reason',
        'source',
        'metadata',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('focal-marketing.tables.suppressions', 'focal_marketing_suppressions');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Check if an email address is globally suppressed.
     */
    public static function isSuppressed(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        return static::query()
            ->where('email', $email)
            ->exists();
    }

    /**
     * Add an email address to the global suppression list.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function suppress(
        string $email,
        string $reason = 'hard_bounce',
        ?string $source = null,
        ?array $metadata = null
    ): self {
        $email = mb_strtolower(trim($email));

        /** @var self $suppression */
        $suppression = static::query()->firstOrCreate(
            ['email' => $email],
            [
                'reason' => $reason,
                'source' => $source,
                'metadata' => $metadata,
            ]
        );

        return $suppression;
    }

    /**
     * Remove an email from the suppression list.
     */
    public static function remove(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return (bool) static::query()->where('email', $email)->delete();
    }
}
